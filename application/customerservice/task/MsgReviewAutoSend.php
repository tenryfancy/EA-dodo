<?php
namespace app\customerservice\task;

use app\index\service\AbsTasker;
use app\common\model\MsgEmailSend;
use think\Exception;
use app\common\exception\TaskException;
use app\common\service\UniqueQueuer;
use app\customerservice\queue\MsgReviewAutoSendQueue;
use app\common\cache\Cache;

class MsgReviewAutoSend extends AbsTasker
{
    //任务redis键前缀
    private $task_key_prefix = 'task:customerservice:MsgReviewAutoSend:';
    //任务过期时间（秒）
    private $task_expired_time = 120;
    //队列redis键前缀
    private $queue_key_prefix = 'queue:customerservice:MsgReviewAutoSendQueue:';
    //队列过期时间（秒）
    private $queue_expired_time = 300;

    //渠道id
    private $channel_id = null;
    
    public function getCreator() {
        return 'wangwei';
    }

    public function getDesc() {
        return '站内信/评价自动发送';
    }

    public function getName() {
        return '站内信/评价自动发送';
    }

    public function getParamRule() {
        return [
            'channel_id|渠道'=>'require|select:ebay:1,amazon:2,aliexpress:4'
        ];
    }
    
    public function execute(){        
        //运行检查
        if(!$this->runCheck()){
            return false;
        }
        
        try {
            $this->channel_id = $this->getData('channel_id');
            
            if(!$this->channel_id){
                throw new TaskException('未获取到channel_id');
            }
            $where = [
                'status'=>'0',
                'cron_time'=>['<=', time()],
                'channel_id'=>$this->channel_id,
            ];
            $order = 'cron_time asc';
            $page = 1;
            $pageSize = 1000;
            while ($rows = MsgEmailSend::where($where)->order($order)->page($page, $pageSize)->select()){
                foreach ($rows as $row){
                    //msg_rule_set_id为空的不需检查
                    if ($row['msg_rule_set_id'] && !$this->push_check($row['channel_id'],$row['receiver'])){
                        continue;
                    }
                    $sendData = [
                        'msg_email_send_id'=>$row['id'],//Y 站内信/邮件发送表id
                        'channel_id'=>$row['channel_id'],//Y 渠道id
                        'account_id'=>$row['account_id'],//Y 账号id
                        'channel_order_number'=>$row['channel_order_number'],//Y 渠道订单号
                        'send_email_rule'=>$row['send_email_rule'],//Y 发送邮箱方式
                        'template_id'=>$row['template_id'],//Y 模板id
                        'event_name'=>$row['trigger_rule'],//N 事件名称
                        'msg_rule_set_id'=>$row['msg_rule_set_id'],//N 规则设置表id
                        'receiver'=>$row['receiver'],//Y 收信人
//                        'item_id'=>$row['item_id'],//N ebay 商品唯一id
//                        'sender'=>$row['sender'],//N ebay 站内信消息发送人
                        'extra_params'=>json_decode($row['extra_params'],true),//N 自动发信参数 json格式
                    ];
                    //状态改为“加入队列”
                    $row->save(['status'=>'1']);
                    //加入列队
                    (new UniqueQueuer(MsgReviewAutoSendQueue::class))->push(json_encode($sendData));
                }
                //更新运行时间
                $this->updateRunTime();
                //休息一秒
                sleep(1);
            }
            
            //运行结束
            $this->runEnd();
            
        } catch (Exception $ex) {
            //运行结束
            $this->runEnd();
            
            throw new TaskException($ex->getMessage());
        }
    }


    /**
     * @desc 运行检查
     * @author denghaibo
     * @date 2019-3-9 17:19:48
     * @return boolean
     */
    private function push_check($channel_id,$receiver)
    {
        $key = $this->queue_key_prefix . $channel_id . $receiver;
        if (Cache::handler()->exists($key)){
            return false;
        }else{
            //设置原子锁
            return Cache::handler()->set($key, time(),['nx', 'ex' => $this->queue_expired_time]);
        }
    }

    /**
     * @desc 运行检查
     * @author wangwei
     * @date 2018-9-25 17:34:59
     * @return boolean
     */
    private function runCheck()
    {
        //设置超时时间
        set_time_limit(0);
        
        //获取redis
        $key = $this->task_key_prefix . $this->channel_id;
        if($run_time = Cache::handler()->get($key)){
            return time() - $run_time > $this->task_expired_time;
        }else{
            //设置原子锁
            return Cache::handler()->set($key, time(),['nx', 'ex' => $this->task_expired_time]);
        }
    }
    
    /**
     * @desc 更新运行时间
     * @author wangwei
     * @date 2018-9-25 18:34:48
     * @return unknown
     */
    private function updateRunTime()
    {
        $key = $this->task_key_prefix . $this->channel_id;
        return Cache::handler()->set($key, time());
    }
    
    /**
     * @desc 运行结束
     * @author wangwei
     * @date 2018-9-25 18:43:46
     */
    private function runEnd()
    {
        $key = $this->task_key_prefix . $this->channel_id;
        Cache::handler()->del($key);
    }
    
}
