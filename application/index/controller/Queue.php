<?php
/**
 * Created by PhpStorm.
 * User: wuchuguang
 * Date: 17-8-4
 * Time: 上午10:38
 */

namespace app\index\controller;

use app\common\cache\Cache;
use app\common\exception\JsonErrorException;
use app\index\service\Queue as QueueServer;
use app\common\controller\Base;
use swoole\messageAction\KillTask;
use swoole\SwooleCmder;
use think\Request;
use think\Config;
use \swoole\cmd\SetTableQueue;

/**
 * @module 系统管理
 * @title 队列管理
 * @url /queue
 */
class Queue extends Base
{
    public function index(QueueServer $queue)
    {
        $queues = $queue->getQueues();
        return json($queues);
    }
    
    /**
     * @title 队列类列表
     * @url /queue/classes
     * @method get
     */
    public function queueClasses(QueueServer $queue)
    {
    	return json($queue->getQueuesClass());
    }
    
    /**
     * @title 安装
     * @url /queue/install
     * @method get
     */
    public function queueInstall(QueueServer $server, Request $request)
    {
    	$qclass = $request->get('queue_class');
    	$result = $server->installQueue($qclass);
    	return json(['message'=>'ok']);
    }
    
    /**
     * @title 卸载
     * @url /queue/uninstall
     * @method get
     */
    public function queueUninstall(QueueServer $server, Request $request)
    {
    	$qclass = $request->get('queue_class');
    	$result = $server->uninstallQueue($qclass);
    	return json(['message'=>'ok']);
    }
    
    /**
     * @title 卸载
     * @url /queue/initInstall
     * @method get
     */
    public function initQueueInstall(QueueServer $server, Request $request){
    	$result = $server->initQueueInstall();
    	return json(['message'=>'ok']);
    }

    /**
     * @title 重新获取队列数据
     * @url reload
     * @method post
     */
    public function reload(QueueServer $queue, Request $request)
    {
        $queuer = $request->param('queuer');
        return json($queue->reload($queuer));
    }

    /**
     * @title 重新获取队列当前进度
     * @url schedule
     * @method get
     *
     * @return \think\response\Json
     * @throws \think\Exception
     */
    public function taskSchedule()
    {
        $cache = Cache::store('queuer');
        $schedules = $cache->taskGets();
        $result = [];
        foreach ($schedules as $taskId => $schedule){
            $schedule = unserialize($schedule);
            $schedule['taskId'] = $taskId;
            $result[] = $schedule;
        }
        return json($result);
    }

    /**
     * @title 重新获取队列元素
     * @url elements
     * @method get
     */
    public function elements(QueueServer $queue, Request $request)
    {
        $key = $request->param('key');
        return json($queue->elementsCount($key));
    }

    /**
     * @title 清空队列元素
     * @url clear
     * @method post
     */
    public function clear(QueueServer $queue, Request $request)
    {
        $key = $request->param('key');
        $hosttype = $request->param('hosttype');
        if(! $key){
        	throw new JsonErrorException("必需指定队列");
        }
        $queue->clear($key, $hosttype);
        return json(['message'=>'清空成功']);
    }

//     public function timeout(QueueServer $queue, Request $request)
//     {
//         $key = $request->param('key');
//         $timeout = $request->param('timeout');
//         $queue->setTimeout($key, $timeout);
//         return json(['message'=>'设置成功']);
//     }

    /**
     * @title 获取队列日志
     * @url logs
     * @method get
     */
    public function logs(QueueServer $queue, Request $request)
    {
        $key = $request->param('key');
        $start = $request->param('start', 0);
        $end = $request->param('end', 20);
        return json($queue->logs($key, $start, $end));
    }

    /**
     * @param QueueServer $queue
     * @param Request $request
     * @title 删除队列元素（正在执行中的元素没中断）
     * @method delete
     * @url remove-element
     */
    public function removeElement(QueueServer $queue, Request $request)
    {
        $key = $request->param('key');
        $ele = $request->param('element');
        $result = $queue->removeElement($key, serialize($ele));
        if(! $result && is_string($ele)){
        	$tmp = json_decode($ele, true);
        	$result = $tmp ? $queue->removeElement($key, serialize($tmp)) : null;
        }
        return json(['message' =>"删除成功"]);
    }

    /**
     * @title 设置队列所在runtype
     * @url change-runtype
     * @method put
     *
     */
    public function changeRuntype(QueueServer $queue, Request $request)
    {
        $queuer = $request->param('queuer');
        $hosttype =$request->param('hosttype');
        if(! $queuer){
        	throw new JsonErrorException("必需指定队列");
        }elseif(! $hosttype){
        	throw new JsonErrorException("必需指定执行器类型");
        }
        $types = Config::get('swoole.host_types');
        $result = $queue->setRuntype($queuer, $hosttype, $types);
        if($result === false){
        	return json_error('修改失败');
        }else{
        	return json(['message'=> '设置成功']);
        }
    }

    /**
     * @title 获取队列runtype列表
     * @url runtypes
     * @method get
     *
     */
    public function runtypes()
    {
    	$result = [];
    	$types = Config::get('swoole.host_types');
    	foreach ($types as $t => $config){
    		$result[] = ['hosttype' => $t];
    	}
    	return json($result);
    }

    /**
     * @title 获取状态信息
     * @url status
     * @method get
     */
    public function status(QueueServer $queue, Request $request)
    {
    	$status = [];
    	$hosttype = $request->param('hosttype');
    	$types = Config::get('swoole.host_types');
    	if($hosttype){
    		$tmp = $queue->queueStatus($types[$hosttype] ?? null, $hosttype);
    		if(is_array($tmp)){
    			$tmp['hosttype'] = $hosttype;
    		}else{
    			$tmp .= "hosttype: $hosttype";
    		}
    		$status[] = $tmp;
    	}else{
    		foreach ($types as $hosttype => $config){
    			$tmp = $queue->queueStatus($config, $hosttype);
    			if(is_array($tmp)){
    				$tmp['hosttype'] = $hosttype;
    			}else{
    				$tmp .= "hosttype: $hosttype";
    			}
    			$status[] = $tmp;
    		}
    	}
    	return json($status);
    }

    /**
     * @title 强制关闭队列进程
     * @url force-kill
     * @method get
     */
    public static function kill(QueueServer $queue, Request $request)
    {
        $key = $request->get('key', \app\index\queue\Test::class);
        $task = $request->get('task');
        $hosttype = $request->get('hosttype');
        $config = null;
        if($hosttype){
        	$types = Config::get('swoole.host_types');
        	$config = $types[$hosttype] ?? null;
        }
        $cmder = SwooleCmder::create($config);
        $result = $cmder->send(new \swoole\cmd\KillTask(['key'=>$key, 'task'=>$task]));
        return json(['message'=>$result->getResult()]);
    }

    /**
     * @title 修改指定队列的当前运行状态
     * @url change-run-status
     */
    public function changeRunStatus(QueueServer $queue, Request $request)
    {
        $params = $request->param();
        if(!$status = param($params, 'status')){
            throw new JsonErrorException("必需指定状态");
        }
        if(!$taskId = param($params, 'taskId')){
            throw new JsonErrorException("必需指定队列所在taskId");
        }
        if(!$queuer = param($params, 'queuer')){
            throw new JsonErrorException("必需指定队列");
        }
        $config = null;
        if(isset($params['hosttype'])){
        	$types = Config::get('swoole.host_types');
        	$config = $types[$params['hosttype']] ?? null;
        }
        $queue->changeRunStatus($queuer, $taskId, $status, $config);
        return json(['message'=>'修改成功']);
    }

    /**
     * @title 修改队列的状态
     * @method post
     * @url status
     */
    public function changeStatus(QueueServer $queue, Request $request)
    {
        $params = $request->param();
        if(!isset($params['status'])){
            throw new JsonErrorException("必需指定状态");
        }
        if(!$queuer = param($params, 'queuer')){
            throw new JsonErrorException("必需指定队列");
        }
        $status = ($params['status'] == "false" || ! $params['status']) ? false : true;
        $config = null;
        if(isset($params['hosttype'])){
        	$types = Config::get('swoole.host_types');
        	$config = $types[$params['hosttype']] ?? null;
        }
        $queue->changeStatus($queuer, $status, $config);
        return json(['message'=>'修改成功']);
    }

    /**
     * @title 设置swoole的table_queue计数
     * @method put
     * @url queue-count
     */
    public function setSwooleTableQueue(Request $request)
    {
        $params = $request->param();
        if(!$queuer = param($params, 'queuer')){
            throw new JsonErrorException("必需指定队列");
        }elseif(! isset($params['count'])){
            throw new JsonErrorException("必需指定设置的值");
        }
        $config = null;
        if(isset($params['hosttype'])){
        	$types = Config::get('swoole.host_types');
        	$config = $types[$params['hosttype']] ?? null;
        }
        $cmder = SwooleCmder::create($config);
        $result = $cmder->send(new SetTableQueue(['queuer' => $queuer, 'count'=> $params['count']]));
        return json(['message'=>$result->getResult()]);
    }
    
    /**
     * @title 获取waitQueue
     * @url consuming
     * @method get
     */
    public function consumingNews(QueueServer $queue, Request $request)
    {
        $key = $request->param('key');
        $hosttype = $request->param('hosttype');
        if(empty($key)){
            throw new JsonErrorException("缺少参数");
        }
        return json(['message'=>$queue->getConsumingNews($key, $hosttype)]);
    }
    
    /**
     * @title 获取手机验证码
     * @url catpond-code
     * @method get
     */
    public function catpondMessage(Request $request)
    {
        $phone = $request->param('phone');
        $instruct = $request->param('instruct');
        if (empty($phone)){
            throw new JsonErrorException("必需指定手机号");
        }
        $cmder = new SwooleCmder();
        $result = $cmder->sendToCatpond($phone, null, null, $instruct);
        return json(['message'=>$result]);
    }

}
