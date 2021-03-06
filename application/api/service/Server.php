<?php

namespace app\api\service;

use app\common\cache\Cache;
use app\common\model\App;
use app\common\model\ChannelNode;
use app\common\service\UniqueQueuer;
use app\index\queue\ServerUserReceive;
use app\index\service\ManagerServer;
use app\index\service\ServerLog;
use app\index\service\SoftwareService;
use app\index\service\User;
use app\internalletter\service\InternalLetterService;
use think\Exception;
use think\Request;
use think\Config;
use Odan\Jwt\JsonWebToken;
use app\common\exception\JsonErrorException;

/** 服务器
 * Created by PhpStorm.
 * User: XPDN
 * Date: 2017/8/15
 * Time: 15:56
 */
class Server extends Base
{

    /** 获取服务器渠道账号信息
     * @return array
     * @throws \think\Exception
     */
    public function serverAccounts()
    {
        $computer = $this->requestData['computer'];
        $ip = $this->requestData['ip'];
        $mac = $this->requestData['mac'];
        $loginAccount = $this->requestData['login_account'];
        $networkIp = $this->requestData['network_ip'] ?? '';

        try {
            if(!$loginAccount){
                throw new Exception('用户信息不能为空');
            }
            $message = '';
            $managerServer = new ManagerServer();
            if(!$ip){
                $message .= 'ip地址为空,';
            }
            if(!$mac){
                $message .= 'mac地址为空,';
            }
            if(!$computer){
                $message .= '服务器名称为空,';
            }
            if($message){
                $message = '【以下服务器异常，'.$message.'请处理】
            '.'名称:'.$computer.'，IP:'.$ip;
                $managerServer->sendMessageGroup($message);
            }
            if($message){
                throw new Exception('网络异常');
            }
            $accounts = $managerServer->serverAccount($computer, $ip, $mac, $loginAccount, true,$networkIp);
            $this->retData['accounts'] = $accounts;
            $this->retData['dns'] = $managerServer->getServerDns($computer, $ip, $mac);
            //Cache::handler()->hSet('hash:serverAccounts:log:' . date('Y-m-d', time()), date('Ymd H:i:s'), json_encode(['login_account' => $loginAccount, 'computer' => $computer, 'ip' => $ip, 'mac' => $mac, 'accounts' => $accounts]));
        } catch (Exception $e) {
            //Cache::handler()->hSet('hash:serverAccounts:error:' . date('Y-m-d', time()), date('Ymd H:i:s'), json_encode(['login_account' => $loginAccount, 'computer' => $computer, 'ip' => $ip, 'mac' => $mac, 'error' => $e->getMessage() . $e->getFile() . $e->getLine()], JSON_UNESCAPED_UNICODE));
            $this->retData['accounts'] = [];
            $this->retData['status'] = -1;
            $this->retData['message'] = $e->getMessage();
        }
    }

    /** 获取服务器渠道账号信息【新方法】
     * @return array
     * @throws \think\Exception
     */
    public function serverAccountsNew()
    {
        $computer = $this->requestData['computer'];
        $ip = $this->requestData['ip'];
        $mac = $this->requestData['mac'];
        $channelId = $this->requestData['channel_id'];
        $channelSite = $this->requestData['channel_site'] ?? '';
        $website_url = $this->requestData['website_url'] ?? '';

        $token = $this->requestData['tokens'];
        $loginAccount = $this->checkUser($token);
        if ($loginAccount) {
            try {
                $managerServer = new ManagerServer();
                $accounts = $managerServer->serverAccountNew($computer, $ip, $mac, $loginAccount, true, $channelId);
                $this->retData['accounts'] = $accounts;
                $this->retData['channelNode'] = (new ChannelNode())->getChannelNode($channelId, $channelSite, $website_url);
                $this->retData['dns'] = $managerServer->getServerDns($computer, $ip, $mac);
                //Cache::handler()->hSet('hash:serverAccounts:log:' . date('Y-m-d', time()), date('Ymd H:i:s'), json_encode(['login_account' => $loginAccount, 'computer' => $computer, 'ip' => $ip, 'mac' => $mac, 'accounts' => $accounts]));
            } catch (Exception $e) {
                //Cache::handler()->hSet('hash:serverAccounts:error:' . date('Y-m-d', time()), date('Ymd H:i:s'), json_encode(['login_account' => $loginAccount, 'computer' => $computer, 'ip' => $ip, 'mac' => $mac, 'error' => $e->getMessage() . $e->getFile() . $e->getLine()], JSON_UNESCAPED_UNICODE));
                $this->retData['accounts'] = [];
                $this->retData['channelNode'] = [];
                $this->retData['status'] = -1;
                $this->retData['message'] = $e->getMessage();
            }
        }

        return $this->retData;
    }

    /**
     * 获取用户已授权的服务器信息
     * @return array
     * @throws string
     */
    public function userServer()
    {
        //$type = $this->requestData['type'];   //  0-正常服务器  1-刷单服务器
        $loginAccount = $this->requestData['login_account'];
        $password = $this->requestData['password'];
        try {
            $managerServer = new ManagerServer();
            $server = $managerServer->userServer($loginAccount, $password);
            $this->retData['server'] = $server;
            //Cache::handler()->hSet('hash:userServer:log:' . date('Y-m-d', time()), date('Ymd H:i:s'), json_encode(['login_account' => $loginAccount, 'password' => $password, 'server' => $server]));
        } catch (Exception $e) {
            //Cache::handler()->hSet('hash:userServer:error:' . date('Y-m-d', time()), date('Ymd H:i:s'), json_encode(['login_account' => $loginAccount, 'password' => $password, 'error' => $e->getMessage() . $e->getFile() . $e->getLine()], JSON_UNESCAPED_UNICODE));
            $this->retData['server'] = [];
            $this->retData['status'] = -1;
            $this->retData['message'] = $e->getMessage();
        }
        return $this->retData;
    }

    /**
     * 获取用户已授权的服务器信息--通过域名方式
     * @return array
     * @throws string
     */
    public function domainServer()
    {
        //$type = $this->requestData['type'];   //  0-正常服务器  1-刷单服务器
        $loginAccount = $this->requestData['login_account'];
        $domain = $this->requestData['domain'];
        try {
            $managerServer = new ManagerServer();
            $server = $managerServer->domainServer($loginAccount, $domain);
            $this->retData['server'] = $server;
            //Cache::handler()->hSet('hash:domainServer:log:' . date('Y-m-d', time()), date('Ymd H:i:s'), json_encode(['login_account' => $loginAccount, 'domain' => $domain, 'server' => $server]));
        } catch (Exception $e) {
            //Cache::handler()->hSet('hash:domainServer:error:' . date('Y-m-d', time()), date('Ymd H:i:s'), json_encode(['login_account' => $loginAccount, 'domain' => $domain, 'error' => $e->getMessage() . $e->getLine() . $e->getLine()], JSON_UNESCAPED_UNICODE));
            $this->retData['server'] = [];
            $this->retData['status'] = -1;
            $this->retData['message'] = $e->getMessage();
        }
        return $this->retData;
    }

    /**
     * 记录访问日志
     * @return array|\think\response\Json
     */
    public function log()
    {
        $data['visit_server_name'] = $this->requestData['name'];
        $data['visit_ip'] = $this->requestData['ip'];
        $data['visit_account_code'] = $this->requestData['code'];
        $data['visit_channel_id'] = $this->requestData['channel_id'];
        $data['type'] = $this->requestData['type'] ?? 0;
        $data['login_account'] = isset($this->requestData['login_account']) ? $this->requestData['login_account'] : 0;
        //Cache::handler()->hSet('hash:server:log:' . date('Y-m-d', time()), date('H', time()), json_encode(['data' => $data]));
        if (empty($data['visit_server_name']) || empty($data['visit_ip']) || empty($data['visit_account_code']) || empty($data['visit_channel_id'])) {
            return json(['message' => '参数值不能为空'], 400);
        }
        $serverLog = new ServerLog();
        $serverLog->add($data);
        $this->retData['message'] = '新增成功';
        return $this->retData;
    }

    /**
     * 检测升级
     * @return array
     * @throws Exception
     */
    public function upgrade()
    {
        $this->requestData = $_GET;
        if (!isset($this->requestData['version'])) {
            throw new Exception('版本检测失败');
        }
        $version = trim($this->requestData['version']);
        $type = $this->requestData['type'];
        $mac = $this->requestData['app'];
        $appModel = new App();
        //查找最新的
        $newApp = $appModel->where(['status' => 1, 'type' => $type])->order('version desc')->limit(1)->find();
        if (!empty($newApp)) {
            $new = str_replace(".", "", $newApp['version']);
            $now = str_replace(".", "", $version);
            if (intval($new) > intval($now)) {
                $this->retData['data']['url'] = $newApp['upgrade_address'];
                $this->retData['message'] = '检查到有新版本';
                $this->retData['status'] = 1;
                if (in_array($type, [2, 3])) {
                    $this->retData['md5'] = $newApp['md5'];
                } else {
                    //记录更新信息
                    (new SoftwareService())->receptionUpdate($mac, $type, $newApp['version']);
                }
                return $this->retData;
            } else {
                $this->retData['message'] = '已是最新版本';
                return $this->retData;
            }
        } else {
            $this->retData['message'] = '已是最新版本';
            return $this->retData;
        }
    }

    /**
     * 人员变更通知
     * @return array
     * @throws Exception
     */
    public function changeNotice()
    {
        $job_number = $this->requestData['job_number'] ?? '';
        if (!empty($job_number)) {
            (new User())->changeStatus($job_number);
        }
        $this->retData['message'] = '操作成功';
        return $this->retData;
    }

    /**
     * 新增人员
     * @return array
     * @throws Exception
     */
    public function addUser()
    {
        $userData['job_number'] = $this->requestData['job_number'] ?? '';
        $userData['username'] = $this->requestData['username'] ?? '';
        $userData['email'] = $this->requestData['email'] ?? '';
        $userData['mobile'] = $this->requestData['mobile'] ?? '';
        $userData['realname'] = $this->requestData['realname'] ?? '';
        $userData['sex'] = $this->requestData['sex'] ?? 0;
        //Cache::handler()->hSet('hash:Server:addUser:log:' . date('Y-m-d', time()), date('H', time()), json_encode(['data' => $userData]));
        $bool = (new User())->addUserByOa($userData);
        $this->retData['message'] = '新增成功';
        if (!$bool) {
            throw new Exception('新增失败');
        }
        return $this->retData;
    }

    /**
     * 服务器定时上报
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function reporting()
    {
        $data['mac'] = $this->requestData['mac'];
        $data['name'] = $this->requestData['name'];
        $data['ip'] = $this->requestData['ip'];
        $network_ip = $this->requestData['network_ip'];
        $serverModel = new \app\common\model\Server();
        //查询表
        $serverInfo = $serverModel->where(['mac' => $data['mac'], 'ip' => $data['ip'], 'name' => $data['name']])->field('id,reporting_cycle,ip_type,network_ip')->find();
        if (empty($serverInfo)) {
            $this->retData['status'] = -1;
            $this->retData['message'] = '服务器不存在';
        } else {
            // 1.判断IP类型，如果为静态的，则需要匹配 外网IP是否一致
            if ($serverInfo['ip_type'] == 0 && $network_ip != $serverInfo['network_ip']) {
                //2. 不一致。 给相关部门发钉钉通知
                $this->retData['status'] = -1;
                $this->retData['message'] = '外网IP不正确';
                //发送通知
//                $content = '【上报外网IP不正确】服务器名称:' . $data['name'] . ',上报外网ip:' . $network_ip . ',设置值:' . $serverInfo['network_ip'];
//                $userIds = [316];
//                $this->sendMessage($userIds, $content, '外网IP不正确');
            } else {
                $this->retData['cycle'] = $serverInfo['reporting_cycle'];
                //更新上报时间
                $serverModel->where(['id' => $serverInfo['id']])->update(['reporting_time' => time()]);
            }
        }
        return $this->retData;
    }

    /**
     * 拉取平台自动登录节点表的 网站地址信息
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getAllChannelNodeUrl()
    {
        $mac = $this->requestData['mac'];
        $ip = $this->requestData['ip'];
        $computer = $this->requestData['computer'];
        $user_agent = (new ManagerServer())->getUserAgent($computer, $ip, $mac);
        $this->retData['node_list'] = (new ChannelNode())->getChannelNodeUrl();
        $this->retData['guide'] = [
            'check_url' => 'http://www.zrzsoft.com/chrome_plug/',
            'jump_url' => 'http://www.zrzsoft.com/chrome_plug/onprobation.html',
            'version' => '1.0.0',
            'userAgent' => $user_agent
        ];
        return $this->retData;
    }

    /**
     * 登录接口获取token
     * @return array
     */
    public function getToken()
    {
        $user_model = new \app\common\model\User();
        $username = $this->requestData['user_name'];
        $password = $this->requestData['user_pwd'];
        if (!$username || !$password) {
            $this->retData['status'] = -1;
            $this->retData['message'] = '账号或者密码不能为空';
            return $this->retData;
        }
        $result = $user_model->login($username, $password, 0);
        if (!$result['state']) {
            $this->retData['status'] = -1;
            $this->retData['message'] = '账号或者密码不正确';
        } else {
            $this->retData['tokens'] = $result['token'];
        }
        return $this->retData;
    }

    /**
     * 检查是否为合法用户
     * @param $auth
     * @return bool
     */
    public function checkUser($auth)
    {
        $key = Config::get('jwt_key');
        $jwt = new JsonWebToken();
        $payload = $jwt->decode($auth, $key);
        if (!$payload['valid']) {
            $this->retData['status'] = -101;
            $this->retData['message'] = 'Error: Token is not valid';
            return false;
        }
        return $payload['claimset']['user_id'];
    }

    /**
     * 发送钉钉通知
     * @param $userIds
     * @param $content
     * @param $title
     * @return bool
     */
    public function sendMessage($userIds, $content, $title)
    {
        $params = [
            'receive_ids' => $userIds,
            'title' => $title,
            'content' => $content,
            'type' => 2,
            'dingtalk' => 1,
            'create_id' => 1
        ];
        return InternalLetterService::sendLetter($params);
    }

    /**
     * 记录用户代理信息
     * @return array
     */
    public function recordUserAgent()
    {
        $computer = $this->requestData['computer'];
        $ip = $this->requestData['ip'];
        $mac = $this->requestData['mac'];
        $user_agent = $this->requestData['user_agent'] ?? '';
        try {
            (new ManagerServer())->recordUserAgent($computer, $ip, $mac, $user_agent);
            $this->retData['message'] = '操作成功';
        } catch (Exception $e) {
            $this->retData['message'] = $e->getMessage();
        }
        return $this->retData;
    }


    /** 获取服务器DNS信息
     * @return array
     * @throws \think\Exception
     */
    public function serverDNS()
    {
        $computer = $this->requestData['computer'];
        $ip = $this->requestData['ip'];
        $mac = $this->requestData['mac'];
        try {
            $managerServer = new ManagerServer();
            $dns = $managerServer->getServerDns($computer, $ip, $mac);
            $this->retData['dns'] = $dns;
        } catch (Exception $e) {
            $this->retData['dns'] = [];
            $this->retData['status'] = -1;
            $this->retData['message'] = $e->getMessage();
        }

        return $this->retData;
    }

    /**
     * 创建服务器用户回调
     * @return array
     */
    public function serverUserUpdate()
    {
        $serverId = $this->requestData['server_id'];
        $data = $this->requestData['userAd'];
        try {
            (new UniqueQueuer(ServerUserReceive::class))->push($this->requestData);
        } catch (\Exception $e) {
            $this->retData['status'] = -1;
            $this->retData['message'] = $e->getMessage();
        }
        return $this->retData;
    }


    /**
     * 获取手机验证码
     * @return array
     */
    public function phoneCode()
    {
        $loginAccount = $this->requestData['login_account'];
        $oldcode = $this->requestData['oldcode'];
        $logintime = $this->requestData['logintime'];
        $user = (new ManagerServer())->getUserInfoByLogin($loginAccount);
        if ($user) {
            $userId = $user['id'];
            $id = $this->requestData['shop_id'];
            $result = (new ManagerServer())->getAccountPhoneCode($id, $userId, $oldcode, $logintime);
            if ($result) {
                if ($result['code'] == 'success' && $result['result']) {
                    $this->retData['phone_code'] = $result['result'];
                    $this->retData['message'] = '获取成功';
                } else {
                    $this->retData['message'] = '验证码获取失败请重新获取';
                    $this->retData['status'] = '1003';
                }
            } else {
                $this->retData['message'] = '店铺不属于你的';
                $this->retData['status'] = '1002';
            }
        }
        return $this->retData;
    }

    private function getUserId($loginAccount)
    {

    }

}