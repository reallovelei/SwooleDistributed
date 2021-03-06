<?php
/**
 * Created by PhpStorm.
 * User: zhangjincheng
 * Date: 17-2-24
 * Time: 下午1:16
 */

namespace Server\Components\TimerTask;


use Server\Asyn\HttpClient\HttpClientPool;
use Server\Components\Event\Event;
use Server\Components\Event\EventDispatcher;
use Server\CoreBase\CoreBase;
use Server\CoreBase\SwooleException;
use Server\Coroutine\Coroutine;

class TimerTask extends CoreBase
{
    protected $timer_tasks_used;
    /**
     * @var HttpClientPool
     */
    protected $consul;
    protected $leader_name;
    protected $id;
    const TIMERTASK = 'timer_task';

    public function __construct()
    {
        parent::__construct();
        $this->leader_name = $this->config['consul']['leader_service_name'];
        $this->consul = get_instance()->getAsynPool('consul');
        $this->updateTimerTask();
        $this->timerTask();
        $this->id = swoole_timer_tick(1000, function () {
            $this->timerTask();
        });

        swoole_timer_after(1000, function () {
            Coroutine::startCoroutine([$this, 'updateFromConsul']);
        });

    }


    /**
     * @param null $consulTask
     * @throws SwooleException
     */
    protected function updateTimerTask($consulTask = null)
    {
        $timer_tasks = $this->config->get('timerTask');
        if ($consulTask != null) {
            $timer_tasks = array_merge($timer_tasks, $consulTask);
        }
        $this->timer_tasks_used = [];
        foreach ($timer_tasks as $timer_task) {
            $task_name = $timer_task['task_name'] ?? '';
            $model_name = $timer_task['model_name'] ?? '';
            if (empty($task_name) && empty($model_name)) {
                throw new SwooleException('定时任务配置错误，缺少task_name或者model_name.');
            }
            $method_name = $timer_task['method_name'];
            if (!array_key_exists('start_time', $timer_task)) {
                $start_time = time();
            } else {
                $start_time = strtotime(date($timer_task['start_time']));
            }
            if (!array_key_exists('end_time', $timer_task)) {
                $end_time = -1;
            } else {
                $end_time = strtotime(date($timer_task['end_time']));
            }
            if (!array_key_exists('delay', $timer_task)) {
                $delay = false;
            } else {
                $delay = $timer_task['delay'];
            }
            $interval_time = $timer_task['interval_time'] < 1 ? 1 : $timer_task['interval_time'];
            $max_exec = $timer_task['max_exec'] ?? -1;
            $this->timer_tasks_used[] = [
                'task_name' => $task_name,
                'model_name' => $model_name,
                'method_name' => $method_name,
                'start_time' => $start_time,
                'next_time' => $start_time,
                'end_time' => $end_time,
                'interval_time' => $interval_time,
                'max_exec' => $max_exec,
                'now_exec' => 0,
                'delay' => $delay
            ];
        }
    }

    /**
     * 定时任务
     */
    public function timerTask()
    {
        $time = time();
        foreach ($this->timer_tasks_used as &$timer_task) {
            if ($timer_task['next_time'] < $time) {
                $count = round(($time - $timer_task['start_time']) / $timer_task['interval_time']);
                $timer_task['next_time'] = $timer_task['start_time'] + $count * $timer_task['interval_time'];
            }
            if ($timer_task['end_time'] != -1 && $time > $timer_task['end_time']) {//说明执行完了一轮，开始下一轮的初始化
                $timer_task['end_time'] += 86400;
                $timer_task['start_time'] += 86400;
                $timer_task['next_time'] = $timer_task['start_time'];
                $timer_task['now_exec'] = 0;
            }
            if (($time == $timer_task['next_time']) &&
                ($time < $timer_task['end_time'] || $timer_task['end_time'] == -1) &&
                ($timer_task['now_exec'] < $timer_task['max_exec'] || $timer_task['max_exec'] == -1)
            ) {
                if ($timer_task['delay']) {
                    $timer_task['next_time'] += $timer_task['interval_time'];
                    $timer_task['delay'] = false;
                    continue;
                }
                $timer_task['now_exec']++;
                $timer_task['next_time'] += $timer_task['interval_time'];
                EventDispatcher::getInstance()->randomDispatch(TimerTask::TIMERTASK, $timer_task);
            }
        }
    }

    /**
     * @param int $index
     */
    public function updateFromConsul($index = 0)
    {
        $result = yield $this->consul->httpClient->setMethod('GET')
            ->setQuery(['index' => $index, 'key' => '*', 'recurse' => true])
            ->coroutineExecute("/v1/kv/TimerTask/{$this->leader_name}/")->setTimeout(11 * 60 * 1000)->noException(null);
        if ($result == null) {
            Coroutine::startCoroutine([$this, 'updateFromConsul'], [$index]);
            return;
        }
        $body = json_decode($result['body'], true);
        $consulTask = [];
        if ($body != null) {
            foreach ($body as $value) {
                $consulTask[$value['Key']] = json_decode(base64_decode($value['Value']), true);
            }
            $this->updateTimerTask($consulTask);
        }
        $index = $result['headers']['x-consul-index'];
        Coroutine::startCoroutine([$this, 'updateFromConsul'], [$index]);
    }

    /**
     * start
     */
    public static function start()
    {
        EventDispatcher::getInstance()->add(TimerTask::TIMERTASK, function (Event $event) {
            $timer_task = $event->data;
            if (!empty($timer_task['task_name'])) {
                $task = get_instance()->loader->task($timer_task['task_name'], get_instance());
                call_user_func([$task, $timer_task['method_name']]);
                $task->startTask(null);
            } else {
                $model = get_instance()->loader->model($timer_task['model_name'], get_instance());
                Coroutine::startCoroutine([$model, $timer_task['method_name']]);
            }
        });
    }
}