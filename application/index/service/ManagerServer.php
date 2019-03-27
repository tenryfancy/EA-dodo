<?php

namespace app\index\service;

use app\common\cache\Cache;
use app\common\exception\JsonErrorException;
use app\common\exception\ReportException;
use app\common\model\Account;
use app\common\model\AccountUserMap;
use app\common\model\BrowserCustomer;
use app\common\model\ChannelNode;
use app\common\model\ChannelSuperSite;
use app\common\model\ExtranetType;
use app\common\model\LogExportDownloadFiles;
use app\common\model\Server;
use app\common\model\ServerLog;
use app\common\model\ServerUserAccountInfo;
use app\common\model\ServerUserMap;
use app\common\model\User;
use app\common\service\ChannelAccountConst;
use app\common\service\Common;
use app\common\service\CommonQueuer;
use app\common\service\Encryption;
use app\common\service\ImportExport;
use app\common\service\UniqueQueuer;
use app\common\traits\Export;
use app\index\queue\SendServerUpdateUserQueue;
use app\index\queue\ServerExportQueue;
use app\index\queue\ServerUserSendQueue;
use app\internalletter\service\DingTalkService;
use app\report\model\ReportExportFiles;
use superbrowser\SuperBrowserBaseApi;
use think\Db;
use think\Exception;
use think\Loader;


Loader::import('phpExcel.PHPExcel', VENDOR_PATH);

/** 账号服务器
 * Created by PhpStorm.
 * User: phill
 * Date: 2017/8/3
 * Time: 20:23
 */
class ManagerServer
{
    use Export;
    protected $serverModel;
    protected $serverUserMapModel;
    const UserAdd = 1;
    const UserAddGroup = 2;
    const UserDelete = 3;
    const UserChane = 4;
    const UserInfo = 5;

    //服务器类型
    const Virtual = 0;
    const Cloud = 1;
    const Superbrowser = 2;
    const Proxy = 3;
    //服务ip类型
    const Ip_static = 0;
    const Ip_dynamic = 1;


    public $userPrefix = 'rondaful';

    public function __construct()
    {
        if (is_null($this->serverModel)) {
            $this->serverModel = new Server();
        }
        if (is_null($this->serverUserMapModel)) {
            $this->serverUserMapModel = new ServerUserMap();
        }
    }

    /** 组装where 条件
     * @param object $request
     * @return array $where
     */
    public function getWhere($request)
    {
        $name = $request->get('name', '');
        $where = [];
        if (!empty($name)) {
            $where['name|ip'] = ['like', '%' . $name . '%'];
        }
        $params = $request->param();
        if (isset($params['snDate'])) {
            switch ($params['snDate']) {
                case 'created':
                    $condition = timeCondition($params['date_b'], $params['date_e']);
                    if (!is_array($condition)) {
                        return json(['message' => '日期格式错误'], 400);
                    }
                    if (!empty($condition)) {
                        $where['create_time'] = $condition;
                    }
                    break;
                case 'updated':
                    $condition = timeCondition($params['date_b'], $params['date_e']);
                    if (!is_array($condition)) {
                        return json(['message' => '日期格式错误'], 400);
                    }
                    if (!empty($condition)) {
                        $where['update_time'] = $condition;
                    }
                    break;
                case 'reporting':
                    $condition = timeCondition($params['date_b'], $params['date_e']);
                    if (!is_array($condition)) {
                        return json(['message' => '日期格式错误'], 400);
                    }
                    if (!empty($condition)) {
                        $where['reporting_time'] = $condition;
                    }
                    break;
            }
        }
        if (isset($params['snType']) && isset($params['snText']) && !empty($params['snText'])) {
            $text1 = $params['snText'];
            switch (trim($params['snType'])) {
                //服务器名
                case 'name':
                    $where['name'] = ['like', '%' . $text1 . '%'];
                    break;
                //ip
                case 'ip':
                    $where['ip'] = ['like', '%' . $text1 . '%'];
                    break;
                //mac
                case 'mac':
                    $where['mac'] = ['like', '%' . $text1 . '%'];
                    break;
            }
        }
        //外网类型
        if (isset($params['ip_type']) && $params['ip_type'] != '') {
            $where['ip_type'] = ['EQ', $params['ip_type']];
        }
        //类型
        if (isset($params['type'])) {
            if ($params['type'] != -1 && $params['type'] != '') {
                $where['type'] = ['EQ', $params['type']];
            }
        }
        //状态
        if (isset($params['status']) && $params['status'] != '') {
            $where['status'] = ['EQ', $params['status']];
        }

        //上报周期状态
        if (isset($params['reporting_time']) && $params['reporting_time'] != '') {
            $allmsg = ['', '>', '<'];
            $msg = $allmsg[$params['reporting_time']] . ' (' . time() . ' -  `reporting_cycle` * 180 )';
            $where['reporting_time'] = ['exp', $msg];
        }
        return $where;
    }

    /** 服务器列表
     * @param array $where
     * @param $page
     * @param $pageSize
     * @return false|\PDOStatement|string|\think\Collection
     */
    public function serverList(array $where, $page, $pageSize)
    {
        $count = $this->serverModel->field(true)->where($where)->count();
        $serverList = $this->serverModel
            ->field(true)
            ->where($where)
            ->order('id desc,update_time desc')
            ->page($page, $pageSize)->select();
        $extranet_type = [];
        if ($serverList) {

            $where = [];
            $ids = array_column($serverList, 'id');
            $where['status'] = ['<>', 6];
            $where['server_id'] = ['in', $ids];
            $serverUseCount = (new Account())->where($where)->group('server_id')->column('count(*)', 'server_id');
            $extranet_type = (new ExtranetType())->field(true)->column('name', 'id');
            $extranet_type[0] = '';
        }
        foreach ($serverList as &$value) {
            $value['server_type'] = $value['type'];
            switch ($value['type']) {
                case 0:
                    $value['type'] = '虚拟机' . '(' . ($extranet_type[$value['ip_type']]) . ')';
                    break;
                case 1:
                    $value['type'] = '云服器';
                    break;
                case 2:
                    $value['type'] = '超级浏览器';
                    break;
                case 3:
                    $value['type'] = '代理';
                    break;
            }
            $value['use'] = $serverUseCount[$value['id']] ?? 0;
        }
        $result = [
            'data' => $serverList,
            'page' => $page,
            'pageSize' => $pageSize,
            'count' => $count,
        ];
        return $result;
    }

    /** 获取信息
     * @param $id
     * @return array|false|\PDOStatement|string|\think\Model
     */
    public function info($id)
    {
        $serverInfo = $this->serverModel->field(true)->where(['id' => $id])->find();
        return $serverInfo;
    }

    /** 删除
     * @param $id
     */
    public function delete($id)
    {
        try {
            $this->serverModel->where(['id' => $id])->delete();
        } catch (Exception $e) {
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine());
        }
    }

    /**
     * 保存
     * @param $data
     * @return mixed
     */
    public function add($data)
    {
        $encryption = new Encryption();
        try {
            if (empty($data['admin']) && $data['type'] == self::Virtual) {
                $data['admin'] = 'admin';
                $data['password'] = 'aepr683@sz';
            }
            $data['password'] = $encryption->encrypt($data['password']);
            if (isset($data['proxy_user_password'])) {
                $data['proxy_user_password'] = $encryption->encrypt($data['proxy_user_password']);
            }
            $data['create_time'] = time();
            $this->serverModel->allowField(true)->isUpdate(false)->save($data);
            $id = $this->serverModel->id;
            ServerLog::addLog($id, ServerLog::add, $data);
            return $id;
        } catch (Exception $e) {
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine());
        }
    }

    /** 更新
     * @param $data
     * @param $id
     */
    public function update($data, $id)
    {
        $old = $this->serverModel->where(['id' => $id])->find();
        if (isset($data['password']) && !empty($data['password']) && $data['password'] != $old['password']) {
            $encryption = new Encryption();
            $data['password'] = $encryption->encrypt($data['password']);
        } else {
            // 密码不更新就不保存
            unset($data['password']);
        }
        try {
            $data['update_time'] = time();
            $this->serverModel->where(['id' => $id])->update($data);
            ServerLog::addLog($id, ServerLog::update, $data, $old);
        } catch (Exception $e) {
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine());
        }
    }


    /**
     * 设置服务器用户授权
     * @param $server_id
     * @param array $data
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function authorization($server_id, array $data)
    {
        $encryption = new Encryption();
        $server = $this->serverModel->field('ip,admin,password,type')->where(['id' => $server_id])->find()->toArray();
        $server['password'] = $encryption->decrypt($server['password']);
        //原来数据
        $oldUserList = $this->serverUserMapModel->field('username,user_id,password')->where(['server_id' => $server_id])->select();
        $oldUser = [];
        $oldPassword = [];
        foreach ($oldUserList as $k => $value) {
            array_push($oldUser, $value['username']);
            $oldPassword[$value['user_id']] = $value['password'];
        }
        Db::startTrans();
        try {
            //删除之前的绑定的
            $this->serverUserMapModel->where(['server_id' => $server_id])->delete();
            foreach ($data as $k => &$v) {
                if (!isset($v['user_id'])) {
                    throw new JsonErrorException('用户信息不完整');
                }
                //获取用户信息
                $userInfo = Cache::store('user')->getOneUser($v['user_id']);
                if (empty($userInfo)) {
                    throw new JsonErrorException('ID为' . $v['user_id'] . '的用户不存在');
                }
                if (isset($oldPassword[$v['user_id']])) {
                    $v['password'] = $encryption->decrypt($oldPassword[$v['user_id']]);
                } else {
                    //查询数据库
                    $userMapInfo = $this->serverUserMapModel->field('password')->where(['user_id' => $v['user_id']])->find();
                    if (!empty($userMapInfo)) {
                        $v['password'] = $encryption->decrypt($userMapInfo['password']);
                    } else {
                        $password = $encryption->createPassword(8);
                        $v['password'] = $password;
                    }
                }
                $v['username'] = $this->userPrefix . ($userInfo['job_number'] ?? '');
                $temp = $v;
                if (!checkStringIsBase64($temp['password'])) {
                    $temp['password'] = $encryption->encrypt($temp['password']);
                }
                $temp['server_id'] = $server_id;
                $temp['create_time'] = time();
                $this->serverUserMapModel->allowField(true)->isUpdate(false)->save($temp);
            }

            $oldIds = array_column($oldUserList, 'user_id');
            $newIds = array_column($data, 'user_id');
            ServerLog::addLog($server_id, ServerLog::user, $newIds, $oldIds);
            //远程控制
            if ($server['type'] != 2) {
                $this->remoteProcess($server, $oldUser, $data);
            }
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            throw new JsonErrorException($e->getMessage(), 500);
        }
    }

    /**
     * 获取服务器用户授权信息
     * @param $server_id
     * @return array
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function authorizationInfo($server_id, $status = '')
    {
        $where = [
          'server_id' => $server_id,
        ];
        if($status != ''){
            $where['status'] = $status;
        }
        $serverList = $this->serverUserMapModel->field('user_id,password,username,status')->where($where)->select();
        $userData = [];
        $allStatus = ['处理中','成功','失败'];
        foreach ($serverList as $key => $value) {
            $info = [];
            $temp['user_id'] = $value['user_id'];
            $temp['user_label'] = Cache::store('user')->getOneUserRealname($value['user_id']);
            $info['password'] = $value['password'];
            $info['username'] = $value['username'];
            $info['user'] = $temp;
            $info['status'] = $allStatus[$value['status']];
            array_push($userData, $info);
        }
        return $userData;
    }

    /**
     * 通过渠道账号获取服务器信息
     * @param $channel_id
     * @param $account_id
     * @return array|false|\PDOStatement|string|\think\Model
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function serverByAccount($channel_id, $account_id)
    {
        if (empty($channel_id) || empty($account_id)) {
            throw new Exception('参数值不能为空');
        }
        $where['a.channel_id'] = ['=', $channel_id];
        $where['a.account_id'] = ['=', $account_id];
        $serverAccountMapModel = new Account();
        $serverInfo = $serverAccountMapModel->alias('a')->field('s.name,s.ip')->join('server s', 'a.server_id = s.id',
            'left')->where($where)->find();
        return $serverInfo;
    }

    /**
     * 账号绑定页面-获取服务器ip地址
     * @param $channel_id
     * @param $account_id
     * @return false|\PDOStatement|string|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function serverIp($channel_id, $account_id)
    {
        $serverAccountMapModel = new Account();
        $where['channel_id'] = ['<>', $channel_id];
        $where['account_id'] = ['<>', $account_id];
        $mapList = $serverAccountMapModel->field('id')->where($where)->select();
        $server_ids = [];
        foreach ($mapList as $key => $value) {
            array_push($server_ids, $value['id']);
        }
        $serverList = $this->serverModel->field('ip,name')->where('id', 'not in', $server_ids)->select();
        return $serverList;
    }

    /**
     * 不同环境下获取真实的IP
     * @return array|false|string
     */
    public function getVisitIp()
    {
        //判断服务器是否允许$_SERVER
        if (isset($_SERVER)) {
            if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $realip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
                $realip = $_SERVER['HTTP_CLIENT_IP'];
            } else {
                $realip = $_SERVER['REMOTE_ADDR'];
            }
        } else {
            //不允许就使用getenv获取
            if (getenv("HTTP_X_FORWARDED_FOR")) {
                $realip = getenv("HTTP_X_FORWARDED_FOR");
            } elseif (getenv("HTTP_CLIENT_IP")) {
                $realip = getenv("HTTP_CLIENT_IP");
            } else {
                $realip = getenv("REMOTE_ADDR");
            }
        }
        return $realip;
    }

    /**
     * 获取真实IP
     * @return array|false|string
     */
    public function getOnlineIp()
    {
        $ch = curl_init('http://tool.huixiang360.com/zhanzhang/ipaddress.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $a = curl_exec($ch);
        preg_match('/\[(.*)\]/', $a, $ip);
        return $ip[1] ?? '';
    }


    /**
     * 获取服务器渠道账号信息
     * @param $computer
     * @param $ip
     * @param $mac
     * @param $loginAccount
     * @param bool|false $isAllowAdd [不存在是否允许新增  true 允许  false 不允许]
     * @param $networkIp 外网IP
     * @return array|false|\PDOStatement|string|\think\Collection
     * @throws Exception
     */
    public function serverAccount($computer, $ip, $mac, $loginAccount, $isAllowAdd = false, $networkIp = '')
    {
        $accountModel = new Account();
        $userModel = new User();
        $accountUserModel = new AccountUserMap();
        $encryption = new Encryption();
        $where['ip'] = ['=', trim($ip)];
        $where['mac'] = ['=', trim($mac)];
        $serverList = $this->serverModel->field('id,name,ip,network_ip')->where($where)->select();

        $server_ids = [];
        $visit_ip = $this->getVisitIp(); //真实IP
        if (empty($serverList)) {
            if ($isAllowAdd) {
                $serverInfo['name'] = trim($computer);
                $serverInfo['ip'] = trim($ip);
                $serverInfo['mac'] = trim($mac);
                $serverInfo['create_time'] = time();
                $serverInfo['visit_ip'] = $visit_ip;
                if ($networkIp) {
                    $serverInfo['network_ip'] = $networkIp;

                }
                $serverInfo['status'] = 1;
                if ($this->isAliCloud($ip)) {   //阿里云系
                    $serverInfo['admin'] = 'administrator';
                    $serverInfo['password'] = $encryption->encrypt('Bala#20171236');
                } else {
                    $serverInfo['admin'] = 'administrator';
                    $serverInfo['password'] = $encryption->encrypt('aepr683@sz');
                }
                $this->serverModel->allowField(true)->isUpdate(false)->save($serverInfo);
                $server_id = $this->serverModel->id;
                if($networkIp){
                    (new ServerNetwork())->add(['server_id' => $server_id, 'ip' => $networkIp]);
                }

            }
        } else {

            foreach ($serverList as $key => $value) {
                //判断外网ip是否正常
                if ($value['network_ip']) {
                    if (!$networkIp || ($networkIp != $value['network_ip'])) {
                        $message = '【以下服务器外网IP异常，请处理】
            ' . '名称:' . $value['name'] . '，IP:' . $value['ip'] . ',
            ' . '设置值' . $value['network_ip'] . ',上传值' . $networkIp;
                        $this->sendMessageGroup($message);
                        throw new Exception('外网异常，外网' . $networkIp);
                    }

                }
                //if($value['name'] == trim($computer)){
                array_push($server_ids, $value['id']);
                //}
                if($networkIp){
                    (new ServerNetwork())->add(['server_id' => $value['id'], 'ip' => $networkIp]);
                }
            }
        }
        $userInfo = $this->getUserInfoByLogin($loginAccount);
        if (empty($userInfo)) {
            throw new Exception('用户【' . $loginAccount . '】不存在');
        }
        $account_ids = [];
        $accountMap = $accountUserModel->field('account_id')->where(['user_id' => $userInfo['id']])->select();
        foreach ($accountMap as $key => $value) {
            array_push($account_ids, $value['account_id']);
        }
        if (empty($server_ids) || empty($account_ids)) {
            return [];
        }
        $where = [];
        $where['server_id'] = ['in', $server_ids];
        $where['id'] = ['in', $account_ids];
        $where['status'] = ['<', 5];  //查询不作废的
        $accountList = $this->getAccountList($where);
        return $accountList;
    }

    public function sendMessageGroup($message)
    {
        $datas = [
            'chat_id' => 'chat948e2db9f6b95b393997d49c6bc90bc0',
            'content' => $message,
        ];
        $res = DingTalkService::send_chat_message_post($datas);
        return $res;
    }

    public function getUserInfoByLogin($loginAccount)
    {
        $userModel = new User();
        $field = 'id,username,realname';
        if (strpos($loginAccount, $this->userPrefix) !== false) {
            $loginAccount = str_replace($this->userPrefix, '', $loginAccount);
            $loginAccount = str_pad($loginAccount, 4, "0", STR_PAD_LEFT);
            //查出人员信息
            $userInfo = $userModel->field($field)->where(['job_number' => trim($loginAccount), 'status' => 1])->find();
        } else {
            //查出人员信息
            $userInfo = $userModel->field($field)->where(['username' => trim($loginAccount), 'status' => 1])->find();
        }
        return $userInfo;
    }

    public function getServerUserInfoByLogin($loginAccount,$where = [])
    {
        $where['status'] = 0;
        $serverId = (new Server())->where($where)->value('id');
        if(!$serverId){
            throw new Exception('服务器错误');
        }
        $whereMap = [
            'server_id' => $serverId,
            'username' => $loginAccount,
        ];
        $userId = (new ServerUserMap())->where($whereMap)->value('user_id');
        $userModel = new User();
        $field = 'id,username,realname';
        $userInfo = $userModel->field($field)->where(['id' => $userId, 'status' => 1])->find();
        return $userInfo;
    }

    /**
     * 获取服务器渠道账号信息
     * @param $computer
     * @param $ip
     * @param $mac
     * @param $loginAccount
     * @param bool|false $isAllowAdd [不存在是否允许新增  true 允许  false 不允许]
     * @param $channel_id
     * @return array|false|\PDOStatement|string|\think\Collection
     * @throws Exception
     */
    public function serverAccountNew($computer, $ip, $mac, $loginAccount, $isAllowAdd = false, $channel_id = 0)
    {
        $accountModel = new Account();
        $userModel = new User();
        $accountUserModel = new AccountUserMap();
        $encryption = new Encryption();
        $where['ip'] = ['=', trim($ip)];
        $where['mac'] = ['=', trim($mac)];
        $serverList = $this->serverModel->field('id,name')->where($where)->select();
        $server_ids = [];
        if (empty($serverList)) {
            if ($isAllowAdd) {
                $serverInfo['name'] = trim($computer);
                $serverInfo['ip'] = trim($ip);
                $serverInfo['mac'] = trim($mac);
                $serverInfo['create_time'] = time();
                $serverInfo['status'] = 1;
                $serverInfo['visit_ip'] = $this->getVisitIp();
                if ($this->isAliCloud($ip)) {   //阿里云系
                    $serverInfo['admin'] = 'administrator';
                    $serverInfo['password'] = $encryption->encrypt('Bala#20171236');
                } else {
                    $serverInfo['admin'] = 'administrator';
                    $serverInfo['password'] = $encryption->encrypt('aepr683@sz');
                }
                $this->serverModel->allowField(true)->isUpdate(false)->save($serverInfo);
            }
        } else {
            foreach ($serverList as $key => $value) {
                //if($value['name'] == trim($computer)){
                array_push($server_ids, $value['id']);
                //}
            }
        }
//        if (strpos($loginAccount, $this->userPrefix) !== false) {
//            $loginAccount = str_replace($this->userPrefix, '', $loginAccount);
//            $loginAccount = str_pad($loginAccount, 4, "0", STR_PAD_LEFT);
//            //查出人员信息
//            $userInfo = $userModel->field('id')->where(['job_number' => trim($loginAccount),'status' => 1])->find();
//        } else {
//            //查出人员信息
//            $userInfo = $userModel->field('id')->where(['username' => trim($loginAccount),'status' => 1])->find();
//        }
//        if (empty($userInfo)) {
//            throw new Exception('用户【'.$loginAccount.'】不存在');
//        }

        $userInfo['id'] = $loginAccount;

        $account_ids = [];
        $accountMap = $accountUserModel->field('account_id')->where(['user_id' => $userInfo['id']])->select();
        foreach ($accountMap as $key => $value) {
            array_push($account_ids, $value['account_id']);
        }
        if (empty($server_ids) || empty($account_ids)) {
            return [];
        }
        $where = [];
        $where['server_id'] = ['in', $server_ids];
        $where['id'] = ['in', $account_ids];
        $where['status'] = ['<', 5];  //查询不作废的
        $reDatas = $this->getAccountList($where, $channel_id);
        return $reDatas;
    }

    /**
     * 拉取平台登录账号信息，并加密登录密码
     * @param $where
     * @param int $channel_id
     * @return array
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function getAccountList($where, $channel_id = 0)
    {
        $accountModel = new Account();
        $encryption = new Encryption();
        $accountList = $accountModel->field('id,channel_id,account_code as code,site_code,account_name,password,account_name_minor,password_minor')->where($where)->select();
        $reDatas = [];
        foreach ($accountList as $key => $value) {
            if ($channel_id > 0 && $value['channel_id'] != $channel_id) {
                continue;
            }
            if (strpos($value['site_code'], ',') !== false) {
                $value['site_code'] = explode(',', $value['site_code']);
            } else {
                $value['site_code'] = [$value['site_code']];
            }
            switch ($value['channel_id']) { //目前wish做特殊处理
                case ChannelAccountConst::channel_wish:
                    if ($value['account_name_minor'] && $value['password_minor']) {
                        $value['account_name'] = $value['account_name_minor'];
                        $value['password'] = $value['password_minor'];
                    }
                    break;
                default:
            }
            $password = $encryption->decrypt($value['password']);
            $value['password'] = $encryption->encryptByCerts($password, true);
            $value['channel_name'] = !empty($value['channel_id']) ? Cache::store('channel')->getChannelName($value['channel_id']) : '';
            unset($value['account_name_minor']);
            unset($value['password_minor']);
            $reDatas[] = $value;
        }
        return $reDatas;
    }

    /**
     * 获取用户已授权的服务器信息
     * @param $type
     * @param $loginAccount
     * @param $password
     * @return false|\PDOStatement|string|\think\Collection
     * @throws Exception
     */
    public function userServer($loginAccount, $password, $type = 0)
    {
        $userModel = new User();
        $encryption = new Encryption();
        //查出人员信息
        $userInfo = $userModel->field('id,salt,password')->where(['username' => trim($loginAccount), 'status' => 1])->find();
        if (empty($userInfo)) {
            $userInfo = $userModel->field('id,salt,password')->where(['mobile' => trim($loginAccount), 'status' => 1])->find();
            if (empty($userInfo)) {
                throw new Exception('用户不存在');
            }
        }
        $password = base64_decode(trim($password));
        if ($userInfo['password'] != User::getHashPassword($password, $userInfo['salt'])) {
            throw new Exception('密码错误');
        }
        $serverList = $this->getServerListByUser($userInfo['id']);
        foreach ($serverList as $key => &$value) {
            $password = $encryption->decrypt($value['password']);
            $value['password'] = $encryption->encryptByCerts($password, true);
            $value['type_name'] = empty($value['type']) ? '正常' : '刷单';
        }
        return $serverList;
    }

    /**
     * 通过域名获取 服务器列表信息
     * @param $loginAccount
     * @param $domain
     * @return array
     * @throws Exception
     */
    public function domainServer($loginAccount, $domain)
    {
        $encryption = new Encryption();
        $userModel = new User();
        $serverData = [];
        if (!empty($domain)) {
            //查出人员信息
            $userInfo = $userModel->field('id,salt,password')->where(['username' => trim($loginAccount), 'status' => 1])->find();
            if (empty($userInfo)) {
                throw new Exception('用户不存在');
            }
            $serverList = $this->getServerListByUser($userInfo['id']);
            foreach ($serverList as $key => &$value) {
                $password = $encryption->decrypt($value['password']);
                $value['password'] = $encryption->encryptByCerts($password, true);
                $value['type_name'] = empty($value['type']) ? '正常' : '刷单';
                $serverData[$value['name']] = $value;
            }
        }
        return array_values($serverData);
    }

    /**
     * 拉取用户所管理的服务器
     * @param $userId
     * @param int $type
     * @return false|\PDOStatement|string|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getServerListByUser($userId, $type = 0)
    {
        $where['s.type'] = $type;
        $where['su.user_id'] = ['=', $userId];
        $where['s.status'] = ['=', 0];
        $serverList = $this->serverUserMapModel->alias('su')->field('s.name,s.ip,s.mac,s.domain,su.password,su.username,s.type')->join('server s',
            's.id = su.server_id', 'left')->where($where)->select();
        return $serverList;
    }

    /**
     * 远程处理服务器用户信息
     * @param array $server
     * @param array $oldUser
     * @param array $data
     * @return bool|mixed
     * @throws Exception
     */
    private function remoteProcess(array $server, array $deleteUser, array $addUser)
    {
        try {
            // 只有类型为虚拟机的服务器才去创建用户
            if ($server['type'] != 0) {
                return true;
            }
            //删除
            foreach ($deleteUser as $k => $user) {
                $this->sendNew(self::UserDelete, $server['ip'], $server['admin'], $server['password'], $user);
            }
            //新增
            foreach ($addUser as $k => $value) {
                $this->sendNew(self::UserAdd, $server['ip'], $server['admin'], $server['password'], $value['username'], $value['password']);
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 发送命令
     * @param $url
     * @return mixed
     * @throws Exception
     */
    public function sendCommand($url, $data = [], $is_auth = true)
    {
        try {
            set_time_limit(0);
            $ch = curl_init();
            curl_reset($ch);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // 跳过host验证
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); // Post提交的数据包
            }
            if ($is_auth) {
                curl_setopt($ch, CURLOPT_USERPWD, 'administrator:Rr21000332');   #Administrator:Rr21000332   Create:Ad36897#
            }
            $ret = curl_exec($ch);
            curl_close($ch);
            return $ret;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 接收请求
     * @param $option
     * @param $bind_ip
     * @param $admin
     * @param $adminPass
     * @param $user
     * @param string $pass
     * @return bool
     * @throws Exception
     */
    private function send($option, $bind_ip, $admin, $adminPass, $user, $pass = '')
    {
        try {
            if (empty($adminPass)) {
                throw new Exception('请先设置服务器管理员密码');
            }
            $url = 'http://' . $bind_ip . ':10088/user_management';
            $data = [
                'user_Name' => $admin,
                'user_Password' => $adminPass,
            ];
            $handle_Type = 1;
            switch ($option) {
                case self::UserAdd:
                    break;
                case self::UserAddGroup:
                    break;
                case self::UserDelete:
                    $handle_Type = 0;
                    break;
                case self::UserChane:
                    break;
                case self::UserInfo:
                    break;
            }
            $userAd = [
                [
                    'handle_Type' => $handle_Type, //handle_Type 1（新增或修改），0（删除）
                    'local_password' => $pass,
                    'local_username' => $user,
                ],
            ];
            $data['userAd'] = $userAd;
            Cache::handler()->hSet('hash:server:' . date('Ymd') . ':' . date('H'), date('Y-m-d H:i:s'), $url . '--' . json_encode($data));
            $result = $this->sendCommand($url, $data, false);
            Cache::handler()->hSet('hash:server:result' . date('Ymd') . ':' . date('H'), date('Y-m-d H:i:s'), json_encode($result));
            $result = json_decode($result, true);
            if (isset($result['status']) && $result['status'] == 'Success') { //请求成功

            } else {
                throw new Exception('服务器【' . $bind_ip . '】设置用户失败');
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 接收请求
     * @param $option
     * @param $bind_ip
     * @param $admin
     * @param $adminPass
     * @param $user
     * @param string $pass
     * @return bool
     * @throws Exception
     */
    public function sendNew($option, $bind_ip, $admin, $adminPass, $user, $pass = '')
    {
        try {
            if (empty($adminPass)) {
                throw new Exception('请先设置服务器管理员密码');
            }
            $url = 'http://' . $bind_ip . ':10088/user_management';
            $data = [
                'user_Name' => $admin,
                'user_Password' => $adminPass,
            ];
            $handle_Type = 1;
            switch ($option) {
                case self::UserAdd:
                    break;
                case self::UserAddGroup:
                    break;
                case self::UserDelete:
                    $handle_Type = 0;
                    break;
                case self::UserChane:
                    break;
                case self::UserInfo:
                    break;
            }
            $userAd = [
                [
                    'handle_Type' => $handle_Type, //handle_Type 1（新增或修改），0（删除）
                    'local_password' => $pass,
                    'local_username' => $user,
                ],
            ];
            $data['userAd'] = $userAd;
            Cache::handler()->hSet('hash:server:' . date('Ymd') . ':' . date('H'), date('Y-m-d H:i:s'), $url . '--' . json_encode($data));
            $result = $this->sendCommand($url, $data, false);
            Cache::handler()->hSet('hash:server:result' . date('Ymd') . ':' . date('H'), date('Y-m-d H:i:s'), json_encode($result));
            $result = json_decode($result, true);
            if (isset($result['status']) && $result['status'] == 'Success') { //请求成功

            } else {
                throw new Exception('服务器【' . $bind_ip . '】设置用户失败');
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 中转服务器
     * @return int|string
     * @throws Exception
     */
    private function getRelayServer()
    {
        $config = Cache::store('configParams')->getConfig('relay_server');
        return $config['value'] ?? '';
    }

    /**
     * 是否为阿里云系
     * @param $ip
     * @return bool
     */
    public function isAliCloud($ip)
    {
        $ipInfo = explode('.', $ip);
        $ipAddress = [120, 112, 139, 119, 121, 123, 182];
        if (in_array($ipInfo[0], $ipAddress)) {
            return true;
        }
        return false;
    }

    /**
     * 设置服务器用户授权
     * @param $server_id
     * @param array $addUser
     * @param array $deleteUser
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function setAuthorization($server_id, array $addUser, array $deleteUser = [], $user = [], $isAll = false)
    {
        if ($isAll) {
            return $this->setAuthorizationAll($server_id, $addUser, $deleteUser, $user);
        }
        $encryption = new Encryption();
        $server = $this->serverModel->field('id,ip,admin,password,type')->where(['id' => $server_id])->find();
        if (empty($server)) {
            return 1;
        }
        $server = $server->toArray();
        $server['password'] = $encryption->decrypt($server['password']);
        $addUserData = [];
        $deleteUserList = [];
        //查询出删除的用户名
        if (!empty($deleteUser)) {
            $deleteUserList = (new ServerUserMap())->field('user_id,username')->where(['server_id' => $server_id])->where('user_id', 'in', $deleteUser)->select();
        }

        Db::startTrans();
        try {
            if (!empty($deleteUser)) {
                $where['server_id'] = ['eq', $server_id];
                $where['user_id'] = ['in', $deleteUser];
                (new ServerUserMap())->where($where)->delete();
                ServerLog::addLog($server_id, ServerLog::user, [], $deleteUser, '', $user);
            }
            //新增
            foreach ($addUser as $k => $user_id) {
                $temp = [];
                //获取用户信息
                $userInfo = Cache::store('user')->getOneUser($user_id);
                //查询数据库
                $userMapInfo = $this->serverUserMapModel->field('password')->where(['user_id' => $user_id])->find();
                if (!empty($userMapInfo)) {
                    $temp['password'] = $encryption->decrypt($userMapInfo['password']);
                    $password = $temp['password'];
                } else {
                    $password = $encryption->createPassword(8);
                    $temp['password'] = $password;
                }
                $temp['username'] = $this->userPrefix . ($userInfo['job_number'] ?? '');
                if (!checkStringIsBase64($temp['password'])) {
                    $temp['password'] = $encryption->encrypt($temp['password']);
                }
                $temp['user_id'] = $user_id;
                $temp['server_id'] = $server_id;
                $temp['create_time'] = time();
                $serverMapInfo = (new ServerUserMap())->where(['user_id' => $temp['user_id'], 'server_id' => $temp['server_id']])->find();
                if (empty($serverMapInfo)) {
                    (new ServerUserMap())->allowField(true)->isUpdate(false)->save($temp);
                }
                $temp['password'] = $password;
                array_push($addUserData, $temp);
            }
            $newIds = array_column($addUserData, 'user_id');
            ServerLog::addLog($server_id, ServerLog::user, $newIds, [], '', $user);
            if ($server['type'] == 0) {
                $params = $this->remoteProcessNew($server, $deleteUserList, $addUserData);
                $this->sendServer($server, $params['userAd']);
            }
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            throw new Exception($e->getMessage());
        }
        return true;
    }

    /**
     * 与服务器通讯批量更新服务器用户信息
     * @param $server
     * @param array $addUserData
     * @param array $deleteUser
     * @throws Exception
     */
    public function sendServerUpdateUser($server, array $addUserData = [], array $deleteUser = [])
    {
        //删除
        foreach ($deleteUser as $k => $user_id) {
            $userInfo = Cache::store('user')->getOneUser($user_id);
            $user = $this->userPrefix . ($userInfo['job_number'] ?? '');
            $this->sendNew(self::UserDelete, $server['ip'], $server['admin'], $server['password'], $user);
        }
        //新增
        foreach ($addUserData as $k => $value) {
            $this->sendNew(self::UserAdd, $server['ip'], $server['admin'], $server['password'], $value['username'], $value['password']);
        }
    }

    public function aaa()
    {
        //$where['create_time'] = ['>',1530633600];
        $where['username'] = ['eq', 'rondaful'];
        $where['user_id'] = ['<>', 1];
        $serverMapList = (new ServerUserMap())->field(true)->where($where)->select();
        $encryption = new Encryption();
        $data = [];
        Db::startTrans();
        try {
            foreach ($serverMapList as $key => $value) {
                $server = $this->serverModel->field('ip,admin,password')->where(['id' => $value['server_id']])->find();
                if (!empty($server)) {
                    $server = $server->toArray();
                    $userInfo = Cache::store('user')->getOneUser($value['user_id']);
                    $server['password'] = $encryption->decrypt($server['password']);
                    $temp['service_password'] = $server['password'];
                    $temp['service_ip'] = $server['ip'];
                    $temp['username'] = $value['username'] . ($userInfo['job_number'] ?? '');
                    $temp['password'] = $encryption->decrypt($value['password']);
                    array_push($data, $temp);
                    (new ServerUserMap())->where(['server_id' => $value['server_id'], 'user_id' => $value['user_id']])->update(['username' => $temp['username']]);
                    //$this->send(self::UserAdd, $server['ip'], $server['admin'], $server['password'], $temp['username'], $temp['password']);
                }
            }
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
        }
        return $data;
    }

    /**
     * 标题-服务器列表
     */
    public function title()
    {
        //id,name,ip,mac,domain,type,admin,password,ip,create_time
        $title = [
            'id' => [
                'title' => 'id',
                'remark' => 'ID',
                'is_show' => 1
            ],
            'name' => [
                'title' => 'name',
                'remark' => '服务器名',
                'is_show' => 1
            ],
            'ip' => [
                'title' => 'ip',
                'remark' => '服务器Ip',
                'is_show' => 1
            ],
            'mac' => [
                'title' => 'mac',
                'remark' => 'mac地址',
                'is_show' => 1
            ],
            'domain' => [
                'title' => 'domain',
                'remark' => '域名',
                'is_show' => 1
            ],
            'type' => [
                'title' => 'type',
                'remark' => '类型',
                'is_show' => 1
            ],
            'admin' => [
                'title' => 'admin',
                'remark' => '管理员',
                'is_show' => 1
            ],
            'password' => [
                'title' => 'password',
                'remark' => '密码',
                'is_show' => 1
            ],
            'create_time' => [
                'title' => 'create_time',
                'remark' => '创建时间',
                'is_show' => 1
            ],
            'status' => [
                'title' => 'status',
                'remark' => '状态',
                'is_show' => 1
            ],
        ];
        return $title;
    }

    /**
     * 标题-服务器成员
     */
    public function titleUser()
    {
        $title = [
            'user_id' => [
                'title' => 'user_id',
                'remark' => '用户ID',
                'is_show' => 1
            ],
            'realname' => [
                'title' => 'realname',
                'remark' => '用户真实名',
                'is_show' => 1
            ],
            'username' => [
                'title' => 'username',
                'remark' => '用户登录名',
                'is_show' => 1
            ],
            'password' => [
                'title' => 'password',
                'remark' => '密码',
                'is_show' => 1
            ],
            'server_name' => [
                'title' => 'server_name',
                'remark' => '服务器名称',
                'is_show' => 1
            ],
            'ip' => [
                'title' => 'ip',
                'remark' => '服务器Ip',
                'is_show' => 1
            ],
            'create_time' => [
                'title' => 'create_time',
                'remark' => '创建时间',
                'is_show' => 1
            ],
        ];

        return $title;
    }

    public function getWhereUser($params)
    {
        $where = [];

        $name = $params['name'];
        $where = [];
        if (!empty($name)) {
            $where['s.name|s.ip'] = ['like', '%' . $name . '%'];
        }

        if (isset($params['snDate'])) {
            switch ($params['snDate']) {
                case 'created':
                    $condition = timeCondition($params['date_b'], $params['date_e']);
                    if (!is_array($condition)) {
                        return json(['message' => '日期格式错误'], 400);
                    }
                    if (!empty($condition)) {
                        $where['s.create_time'] = $condition;
                    }
                    break;
                case 'updated':
                    $condition = timeCondition($params['date_b'], $params['date_e']);
                    if (!is_array($condition)) {
                        return json(['message' => '日期格式错误'], 400);
                    }
                    if (!empty($condition)) {
                        $where['s.update_time'] = $condition;
                    }
                    break;
            }
        }
        if (isset($params['snType']) && isset($params['snText']) && !empty($params['snText'])) {
            $text1 = $params['snText'];
            switch (trim($params['snType'])) {
                //服务器名
                case 'name':
                    $where['s.name'] = ['like', '%' . $text1 . '%'];
                    break;
                //ip
                case 'ip':
                    $where['s.ip'] = ['like', '%' . $text1 . '%'];
                    break;
                //mac
                case 'mac':
                    $where['s.mac'] = ['like', '%' . $text1 . '%'];
                    break;
            }
        }
        //类型
        if (isset($params['type'])) {
            if ($params['type'] != -1 && $params['type'] != '') {
                $where['s.type'] = ['EQ', $params['type']];
            }
        }
        //状态
        if (isset($params['status']) && $params['status'] != '') {
            $where['s.status'] = ['EQ', $params['status']];
        }


        if (isset($params['all']) && $params['all'] == 1) {
            return $where;
        }
        //服务器ID
        if (isset($params['server_ids'])) {
            if ($params['server_ids'] != -1 && $params['server_ids'] != '') {
                $serverIds = json_decode($params['server_ids'], true);
                $where['su.server_id'] = ['in', $serverIds];
            }
        }
        return $where;
    }

    /**
     * 导出记录- 服务器成员
     * @param array $params
     * @return array
     */
    public function exportUser($params = [])
    {
        set_time_limit(0);
        try {

            //ini_set('memory_limit', '4096M');
            if (!isset($params['apply_id']) || empty($params['apply_id'])) {
                throw new Exception('导出申请id获取失败');
            }
            if (!isset($params['file_name']) || empty($params['file_name'])) {
                throw new Exception('导出文件名未设置');
            }
            //获取导出文件名
            $fileName = $params['file_name'];


            $downLoadDir = '/download/server/';
            $saveDir = ROOT_PATH . 'public' . $downLoadDir;
            if (!is_dir($saveDir) && !mkdir($saveDir, 0777, true)) {
                throw new Exception('导出目录创建失败');
            }
            $fullName = $saveDir . $fileName;
            //创建excel对象
            $excel = new \PHPExcel();
            $excel->setActiveSheetIndex(0);
            $sheet = $excel->getActiveSheet();
            $titleRowIndex = 1;
            $dataRowStartIndex = 2;
            $fields = $params['field'] ?? [];
            $titleData = $this->titleUser();
            $title = [];
            if (!empty($fields)) {
                $titleNewData = [];
                foreach ($fields as $k => $v) {
                    if (isset($titleData[$v])) {
                        array_push($title, $v);
                        $titleNewData[$v] = $titleData[$v];
                    }
                }
                $titleData = $titleNewData;
            } else {
                foreach ($titleData as $k => $v) {
                    if ($v['is_show'] == 0) {
                        unset($titleData[$k]);
                    } else {
                        array_push($title, $k);
                    }
                }
            }
            list($titleMap, $dataMap) = $this->getExcelMap($titleData);
            end($titleMap);
            $lastCol = key($titleMap);
            //设置表头和表头样式
            foreach ($titleMap as $col => $set) {
                $sheet->getColumnDimension($col)->setWidth($set['width']);
                $sheet->getCell($col . $titleRowIndex)->setValue($set['title']);
                $sheet->getStyle($col . $titleRowIndex)
                    ->getFill()
                    ->setFillType(\PHPExcel_Style_Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('E8811C');
                $sheet->getStyle($col . $titleRowIndex)
                    ->getBorders()
                    ->getAllBorders()
                    ->setBorderStyle(\PHPExcel_Style_Border::BORDER_THIN);
            }
            $sheet->setAutoFilter('A1:' . $lastCol . '1');
            //统计需要导出的数据行
            $where = $this->getWhereUser($params);
            $count = $this->doServerUserCount($where);
            $pageSize = 2000;
            $loop = ceil($count / $pageSize);

            Cache::handler()->hSet('hash:server:export', 1, 1);
            //分批导出
            for ($i = 0; $i < $loop; $i++) {
                $data = $this->getServerUserData($where, $i + 1, $pageSize);
                foreach ($data as $a => $r) {
                    foreach ($dataMap as $field => $set) {
                        $cell = $sheet->getCell($set['col'] . $dataRowStartIndex);
                        switch ($set['type']) {
                            case 'time':
                                if (empty($r[$field])) {
                                    $cell->setValue('');
                                } else {
                                    $cell->setValue(date('Y-m-d H:i:s', $r[$field]));
                                }
                                break;
                            case 'numeric':
                                $cell->setDataType(\PHPExcel_Cell_DataType::TYPE_NUMERIC);
                                if (empty($r[$field])) {
                                    $cell->setValue(0);
                                } else {
                                    $cell->setValue($r[$field]);
                                }
                                break;
                            default:
                                if (is_null($r[$field])) {
                                    $r[$field] = '';
                                }
                                $cell->setValue($r[$field]);
                        }
                    }
                    $dataRowStartIndex++;
                }
                unset($data);
            }
            Cache::handler()->hSet('hash:server:export', 0, 1);
            $writer = \PHPExcel_IOFactory::createWriter($excel, 'Excel2007');
            $writer->save($fullName);
            if (is_file($fullName)) {
                $applyRecord['exported_time'] = time();
                $applyRecord['download_url'] = $downLoadDir . $fileName;
                $applyRecord['status'] = 1;
                (new ReportExportFiles())->where(['id' => $params['apply_id']])->update($applyRecord);
            } else {
                throw new Exception('文件写入失败');
            }


        } catch (Exception $ex) {
            Cache::handler()->hset(
                'hash:server_export',
                $params['apply_id'] . '_' . time(),
                '申请id: ' . $params['apply_id'] . ',导出失败:' . $ex->getMessage() . $ex->getFile() . $ex->getLine());
            $applyRecord['status'] = 2;
            $applyRecord['error_message'] = $ex->getMessage();
            (new ReportExportFiles())->where(['id' => $params['apply_id']])->update($applyRecord);
        }
    }

    /**
     * 得到导出服务器成员数据的总条数
     * @param $where
     * @return int
     */
    public function doServerUserCount($where)
    {
        $join = $this->getUserJoin();
        return $this->serverUserMapModel->alias('su')->join($join)->field(true)->where($where)->count();
    }


    private function getUserJoin()
    {
        $join[] = ['user u', 'su.user_id = u.id', 'left'];
        $join[] = ['server s', 'su.server_id = s.id', 'left'];
        return $join;
    }

    /**
     * 得到导出服务器成员的数据
     * @param $where
     * @param $page
     * @param $pageSize
     * @return data
     */
    public function getServerUserData($where, $page = 0, $pageSize = 0)
    {
        $field = 'su.user_id,u.realname,su.username,su.password,s.name as server_name,s.ip,su.create_time';

        $join = $this->getUserJoin();
        $serverList = $this->serverUserMapModel->alias('su')->field($field)->join($join)->where($where)->order('server_id ')->page($page, $pageSize)->select();
        $encryption = new Encryption();
        $i = 1;
        $data = [];
        foreach ($serverList as $item) {
            $data[$i]['user_id'] = $item['user_id'];
            $data[$i]['realname'] = $item['realname'];
            $data[$i]['username'] = $item['username'];
            $data[$i]['password'] = $encryption->decrypt($item['password']);
            $data[$i]['server_name'] = $item['server_name'];
            $data[$i]['ip'] = $item['ip'];
            $data[$i]['create_time'] = date('y-m-d H:i:s', $item['create_time']);
            $i++;
        }
        unset($serverList);
        return $data;
    }

    /**
     * 导出记录 -服务器列表
     * @param array $params
     * @param array $field
     * @return array
     */
    public function export($params = [], $field = [])
    {
        set_time_limit(0);
        try {
            //获取导出文件名
            $fileName = '服务器列表' . date('YmdHis', time());
            $downLoadDir = '/download/server/';
            $saveDir = ROOT_PATH . 'public' . $downLoadDir;
            if (!is_dir($saveDir) && !mkdir($saveDir, 0777, true)) {
                throw new Exception('导出目录创建失败');
            }
            $fullName = $saveDir . $fileName;
            $titleData = $this->title();

            $remark = [];
            if (!empty($field)) {
                $title = [];
                foreach ($field as $k => $v) {
                    if (isset($titleData[$v])) {
                        array_push($title, $v);
                        array_push($remark, $titleData[$v]['remark']);
                    }
                }
            } else {
                $title = [];
                foreach ($titleData as $k => $v) {
                    if ($v['is_show'] == 1) {
                        array_push($title, $k);
                        array_push($remark, $v['remark']);
                    }
                }
            }
            $where = $this->getWhere($params);
            $serverList = $this->serverModel->where($where)->order('id ')->column('id,name,ip,mac,domain,type,admin,password,ip,create_time,status', 'id');
            $encryption = new Encryption();
            $alltype = ['虚拟机', '云服务', '超级浏览器', '代理']; //类型  0-正常服务器 1-刷单服务器
            $allStatus = ['启用', '停用']; //类型  0-正常服务器 1-刷单服务器
            foreach ($serverList as &$item) {
                $item['password'] = $encryption->decrypt($item['password']);
                $item['create_time'] = date('y-m-d H:i:s', $item['create_time']);
                $item['type'] = $alltype[$item['type']] ?? $item['type'];
                $item['status'] = $allStatus[$item['status']] ?? $item['status'];
            }
            ImportExport::excelSave($serverList, $remark, $fullName);
            $result = $this->record($fileName, $saveDir);
            return $result;

        } catch (Exception $e) {
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine());
        }
    }

    /**
     * 记录导出记录
     * @param $filename
     * @param $path
     * @return array
     */
    public function record($filename, $path)
    {
        $model = new LogExportDownloadFiles();
        $temp['file_code'] = date('YmdHis');
        $temp['created_time'] = time();
        $temp['download_file_name'] = $filename;
        $temp['type'] = 'server_export';
        $temp['file_extionsion'] = 'xls';
        $temp['saved_path'] = $path . $filename;
        $model->allowField(true)->isUpdate(false)->save($temp);
        return ['file_code' => $temp['file_code'], 'file_name' => $temp['download_file_name']];
    }

    /**
     * 导出申请
     * @param $params
     * @return bool
     * @throws Exception
     * @throws \Exception
     */
    public function exportApply($params)
    {
        $userId = Common::getUserInfo()->toArray()['user_id'];
        $cache = Cache::handler();
        $lastApplyTime = $cache->hget('hash:export_server_apply', $userId);
        if ($lastApplyTime && time() - $lastApplyTime < 5) {
            throw new JsonErrorException('请求过于频繁', 400);
        } else {
            $cache->hset('hash:export_server_apply', $userId, time());
        }
        Db::startTrans();
        try {
            $model = new ReportExportFiles();
            $data['applicant_id'] = $userId;
            $data['apply_time'] = time();
            $data['export_file_name'] = $this->createExportFileName($userId);
            $data['status'] = 0;
            $data['applicant_id'] = $userId;
            $model->allowField(true)->isUpdate(false)->save($data);
            $params['file_name'] = $data['export_file_name'];
            $params['apply_id'] = $model->id;
            (new CommonQueuer(ServerExportQueue::class))->push($params);
            Db::commit();
            return true;
        } catch (\Exception $ex) {
            Db::rollback();
            throw new JsonErrorException('申请导出失败');
        }
    }

    /**
     * 导出申请服务器成员的名字
     * @param $userId
     * @return string
     */
    public function createExportFileName($userId)
    {
        $fileName = '服务器成员_' . $userId . '_' . date("Y_m_d_H_i_s") . '.xlsx';
        return $fileName;
    }

    /**
     * 批量设置上报周期
     * @param $params
     * @return false|int
     */
    public function reporting($params)
    {
        $ids = $params['ids'];
        $reporting_cycle = $params['reporting_cycle'];
        if (!$ids || !$reporting_cycle) {
            throw new JsonErrorException('缺少必要参数');
        }
        $ids = json_decode($ids, true);
        $where['id'] = ['in', $ids];
        return $this->serverModel->save(['reporting_cycle' => $reporting_cycle], $where);
    }


    /**
     * 格式化操作字段
     * @param $data
     * @return array
     */
    public function formdata($data)
    {
        if ($data['type'] == self::Virtual && $data['ip_type'] == self::Ip_dynamic) {
            $data['network_ip'] = '';
        }
        if ($data['type'] == self::Superbrowser) {
            $data['mac'] = '';
            $data['domain'] = '';
            $data['admin'] = '';
            $data['password'] = '';
            $data['reporting_cycle'] = 0;
            $data['network_ip'] = '';
        }
        if ($data['type'] == self::Cloud) {
            $data['mac'] = '';
            $data['domain'] = '';
            $data['reporting_cycle'] = 0;
            $data['network_ip'] = '';
        }
        if ($data['type'] == self::Virtual) {
            $allExtranet = [2, 3, 4, 5, 6];
            if (in_array($data['ip_type'], $allExtranet) && !$data['network_ip']) {
                throw new JsonErrorException('当外网类型为：阿里云、腾讯云、天翼云、华为云、金山云时，外网IP为必填项');
            }
        }

        return $data;
    }

    /**
     * 动态获取规则
     * @param $data
     * @return array
     */
    public function validaterule($data)
    {
        $rule = [
            ['name', 'require|unique:Server,name', '服务器名称不能为空！|服务器名称已存在！'],
            ['ip', 'require|unique:Server,ip', '服务器IP地址不能为空！|服务器IP地址已存在！'],
            ['type', 'require|in:0,1,2,3', '服务器类型不能为空!|服务器类型只能选择虚拟机、云服务、超级浏览器,代理！'],
        ];
        if ($data['type'] == self::Virtual) {
            $rule[] = ['mac', 'require|unique:Server,mac', '服务器MAC地址不能为空!|服务器MAC地址已存在！'];
            $rule[] = ['admin', 'require', '管理员不能为空!'];
            $rule[] = ['password', 'require', '管理员密码不能为空!'];
            $rule[] = ['reporting_cycle', 'require|gt:0', '上报周期不能为空!|上报周期要大于0!'];
            if ($data['ip_type'] == self::Ip_static) {
                $rule[] = ['network_ip', 'require', '外网ip不能为空!'];
            }
        }
        if ($data['type'] == self::Proxy) {
            $rule[] = ['proxy_ip', 'require', '代理IP不能为空!'];
            $rule[] = ['proxy_agent', 'require', '代理协议不能为空!'];
            $rule[] = ['proxy_port', 'require', '代理端口不能为空!'];
        }
        return $rule;
    }

    /**
     * 更新状态
     * @param $data
     * @return false|int
     */
    public function changeStatus($data)
    {
        $id = $data['id'];
        $saveData['status'] = $data['status'] ?? 0;
        if ($saveData['status'] == 1) {
            $where['status'] = ['<>', Account::status_cancellation];
            $where['server_id'] = $id;
            $serverUseCount = (new Account())->where($where)->count();
            if ($serverUseCount > 0) {
                throw new JsonErrorException('该服务器已经被绑定平台资料无法停用');
            }
        }
        $saveData['update_time'] = time();
        $old = $this->serverModel->where(['id' => $id])->find();
        Db::startTrans();
        try {
            $this->serverModel->save($saveData, ['id' => $id]);
            ServerLog::addLog($id, ServerLog::update, $saveData, $old);
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            throw new JsonErrorException($e->getMessage(), 500);
        }
        return true;
    }

    /**
     * 被引用详情
     * @param $id
     * @return false|\PDOStatement|string|\think\Collection
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function useInfo($id)
    {
        $where['status'] = ['<>', 6];
        $where['server_id'] = $id;
        $serverUse = (new Account())->field('channel_id,site_code,account_code,company')->where($where)->select();
        foreach ($serverUse as &$value) {
            $value['channel'] = Cache::store('Channel')->getChannelName($value['channel_id']);
        }
        return $serverUse;
    }

    /**
     * 记录用户代理信息
     * @param $computer
     * @param $ip
     * @param $mac
     * @param $user_agent
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function recordUserAgent($computer, $ip, $mac, $user_agent)
    {
        $where['ip'] = ['eq', trim($ip)];
        $where['mac'] = ['eq', trim($mac)];
        $where['name'] = ['eq', trim($computer)];
        $serverInfo = (new Server())->field('id,user_agent')->where($where)->find();
        if (!empty($serverInfo) && empty($serverInfo['user_agent'])) {
            (new Server())->where(['id' => $serverInfo['id']])->update(['user_agent' => $user_agent]);
        }
    }

    /**
     * 获取用户代理信息
     * @param $computer
     * @param $ip
     * @param $mac
     * @param $user_agent
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getUserAgent($computer, $ip, $mac)
    {
        $where['ip'] = ['eq', trim($ip)];
        $where['mac'] = ['eq', trim($mac)];
        $where['name'] = ['eq', trim($computer)];
        $serverInfo = (new Server())->field('user_agent')->where($where)->find();
        return $serverInfo['user_agent'] ?? '';
    }

    /**
     * 拉取日志
     * @param $id
     * @param int $page
     * @param int $pageSize
     * @return false|\PDOStatement|string|\think\Collection
     */
    public function getLog($id, $page = 1, $pageSize = 50)
    {
        return ServerLog::getLog($id, $page, $pageSize);
    }

    /**
     * 删除服务器成员
     * @param $id
     * @param $userId
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function deleteUser($id, $userId)
    {
        try {
            return $this->setAuthorizationAll($id, [], $userId);
        } catch (Exception $e) {
            throw new JsonErrorException($e->getMessage());
        }
    }

    /**
     * 批量增加服务器信息
     * @param $id
     * @param $userIds
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function addUsers($id, $userIds)
    {
        try {
            $this->setAuthorizationAll($id, $userIds);
        } catch (Exception $e) {
            throw new JsonErrorException($e->getMessage());
        }
        return $this->authorizationInfo($id);
    }


    private $browser_custome_init = 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3359.181 Safari/537.36';

    /**
     * 查询某个用户绑定了平台账号，
     * @param $userId
     * @param int $serverType 服务器类型0-虚拟机 1-云服务 2-超级浏览器  默认2
     * @return array
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getShopListByUserId($userId, $serverType = 2)
    {
        $reData = [];
        $accountIds = (new AccountUserMap())->where('user_id', $userId)->column('account_id');
        if ($accountIds) {
            $join[] = ['server s', 'a.server_id = s.id', 'left'];
            $field = 'a.id,a.channel_id,a.account_code,a.site_code,s.ip,s.proxy,s.user_agent,s.id as sid,s.name';
            $where = [
                'a.id' => ['in', $accountIds],
                's.type' => $serverType,
                'a.status' => ['<>', 5],
            ];
            $list = (new Account())->alias('a')->join($join)->field($field)->where($where)->select();
            foreach ($list as $v) {
                $one = [
                    'website_id' => $v['id'],
                    'website_name' => $v['account_code'],
                    'proxy_id' => $this->getProxy($v), //代理id
                    'site_id' => $v['channel_id'], //平台ID
                    'site_name' => Cache::store('Channel')->getChannelName($v['channel_id']), //平台名称
                    'ip' => $v['ip'],
                    'tags' => [], //所属标签，数组
                    'browser_custome' => $this->getBrowserCustome($v, true), //浏览器版本
                    'init_url' => [], //容器初始页，数组格式
                ];
                $sites = $this->getSuperSites($v['channel_id'], $v['site_code']);
                foreach ($sites as $v) {
                    $one['site_id'] = $v['site_id'];
                    $one['site_name'] = $v['site_name'];
                    $reData[] = $one;
                }
            }
        }
        return $reData;
    }

    private function getSuperSites($channelId, $site = '')
    {
        $reData = [];
        $sites = '';
        if ($site) {
            $sites = explode(',', $site);
        }
        $list = (new ChannelSuperSite())->field('site_id,site_name,channel_site')->where('channel_id', $channelId)->select();
        foreach ($list as $v) {
            $reData[] = [
                'channel_site' => $v['channel_site'],
                'site_id' => $v['site_id'],
                'site_name' => $v['site_name'],
            ];
            return $reData;
        }
        return $reData;
    }

    private function getProxy($server)
    {
        $reData = '';
        if (isset($server['name']) && $server['name']) {
            $data = explode('_', $server['name']);
            $reData = $data[1] ?? '';
        }
        return $reData;
    }

    private function getBrowserCustome($one, $isNew = false)
    {
        $reData = [
            'colordepth' => '', //色彩深度
            'acceptlanguage' => '', //语言类型
            'fontlist' => '', // 字体
            'screensize_width' => '', //屏幕宽度
            'screensize_height' => '', //屏幕高度
        ];
        if ($isNew || !$one['user_agent']) {
            $reData['useragent'] = $this->updateUa($one);
        } else {
            $reData['useragent'] = $one['user_agent'];
        }
        return $reData;
    }


    /**
     * 查询某个用户绑定了代理服务器平台账号，
     * @param $userId
     * @return array
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getAgencyShopListByUserId($userId,$where = [])
    {
        $reData = [];
        $serverId = [];
        $accountIds = (new AccountUserMap())->where('user_id', $userId)->column('account_id');
        if($where){
            $where['status'] = 0;
            $serverId = (new Server())->where($where)->value('id');
            if(!$serverId){
                throw new Exception('服务器错误');
            }
        }
        if ($accountIds) {
            $join[] = ['server s', 'a.server_id = s.id', 'left'];
            $field = 'a.id,a.channel_id,site_code,a.account_code as code,
            s.proxy_ip,s.proxy_agent,s.proxy_port,s.proxy_user_name,s.proxy_user_password,s.ip as public_ip,s.user_agent,s.name,s.id as sid';
            $where = [
                'a.id' => ['in', $accountIds],
                's.type' => 3,
                's.status' => 0
            ];
            if($serverId){
                $where['a.server_id'] = $serverId;
                $where['s.type'] = 0;
            }
            $list = (new Account())->alias('a')->join($join)->field($field)->where($where)->select();
            foreach ($list as $v) {
                $one = $v->toArray();
                if (!$one['user_agent']) {
                    $one['user_agent'] = $this->updateUa($one);
                }
                $one['channel'] = Cache::store('Channel')->getChannelName($v['channel_id']);
                $this->getSiteCode($one);
                $one['account_name'] = $v['code'];
                $one['proxy_port'] = intval($v['proxy_port']);
                unset($one['code']);
                unset($one['site_code']);
                $reData[] = $one;
            }
        }
        return $reData;
    }

    /**
     * 更新服务器的UA信息
     * @param $one
     * @return mixed
     */
    private function updateUa($one)
    {
        $userAgent = BrowserCustomer::getUA();
        if (isset($one['sid']) && $one['sid'] > 0) {
            (new Server())->save(['user_agent' => $userAgent,], ['id' => $one['sid']]);
        }
        return $userAgent;
    }

    private function getSiteCode(&$one)
    {
        switch ($one['channel_id']) {
            case ChannelAccountConst::channel_amazon:
                $one['site'] = [strupper(substr($one['code'], -2))];
                break;
            default:
                $one['site'] = $one['site_code'];
                if (!is_array($one['site'])) {
                    if (!$one['site']) {
                        $one['site'] = [];
                        return true;
                    }
                    if (strpos($one['site'], ',') !== false) {
                        $one['site'] = explode(',', $one['site']);
                    } else {
                        $one['site'] = [$one['site']];
                    }
                }
        }
    }


    /**
     * 获取账号手机验证码
     * @param $id
     * @param int $userId
     * @param $oldcode
     * @param $logintime
     * @return array
     */
    public function getAccountPhoneCode($id, $userId = 0, $oldcode, $logintime)
    {
        $whereMap = [
            'user_id' => $userId,
            'account_id' => $id,
        ];
        $isHas = (new AccountUserMap())->where($whereMap)->column('id');
        if (!$isHas) {
            return [];
        }

        $phone = (new Account())->where('id', $id)->value('phone');
        $cmder = new \swoole\SwooleCmder();
        $result = $cmder->sendToCatpond($phone, $oldcode, $logintime);
        return $result;
    }


    /**
     * 查询某个人员的某个店铺详情信息
     * @param $id
     * @param int $userId
     * @return array
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getAgencyShopDetailByIds($id, $userId = 0)
    {
        $whereMap = [
            'user_id' => $userId,
            'account_id' => $id,
        ];
        $isHas = (new AccountUserMap())->where($whereMap)->column('id');
        if (!$isHas) {
            return [];
        }

        $where = [
            'id' => $id,
        ];
        $info = $this->getAccountList($where);
        $info = $info[0];
        $this->getSiteCode($info);
        $info['site'] = $info['site'][0] ?? '';
        $whereMap = [
            'channel_id' => $info['channel_id'],
        ];
        $other = [];
        $field = 'website_url,channel_site,node_info,verification_website_url,verification_node_info';
        $list = (new ChannelNode())->where($whereMap)->field($field)->select();
        $reData = [
            'account_name' => $info['account_name'],
            'password' => $info['password'],
        ];
        foreach ($list as $k => $v) {
            $v['node_info'] = json_decode($v['node_info'], true);
            $v['verification_node_info'] = json_decode($v['verification_node_info'], true);
            if ($k == 0 || $v['channel_site'] == $info['site']) {
                $reData['website_url'] = $v['website_url'];
            }
            $other[$v['website_url']] = $v;
        }
        $reData['web_list'] = $other;
        $reData['cookie'] = $this->getCookie($id, $userId);

        return $reData;
    }

    public function recordUa($data)
    {

        $reData = false;
        if (!$data['user_id'] || !$data['account_id'] || !$data['user_agent']) {
            return $reData;
        }
        $where = [
            'user_id' => $data['user_id'],
            'account_id' => $data['account_id'],
        ];
        $accountIds = (new AccountUserMap())->where($where)->value('account_id');
        if ($accountIds) {
            $join[] = ['server s', 'a.server_id = s.id', 'left'];
            $where = [
                'a.id' => $accountIds,
                's.type' => 3,
                's.status' => 0,
                's.user_agent' => '',
            ];
            $sid = (new Account())->alias('a')->join($join)->where($where)->value('s.id');
            if ($sid) {
                $oldData['user_agent'] = '';
                $save['user_agent'] = $data['user_agent'];
                (new Server())->save($save, ['id' => $sid]);
                ServerLog::addLog($sid, ServerLog::update, $save, $oldData, $data['user_id'] . '上传回写');
            }
            $reData = true;
        }
        return $reData;
    }

    private function getCookie($id, $userId = 0)
    {
        $reStr = '';
        $where = [
            'user_id' => $userId,
            'account_id' => $id,
        ];
        $info = (new ServerUserAccountInfo())->where($where)->value('cookie');
        if ($info) {
            $reStr = json_decode($info, true);
        }
        return $reStr;
    }

    /**
     * 查询某个人员的某个店铺详情信息
     * @param $id
     * @param int $userId
     * @return array
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getShopDetailByIds($id, $userId = 0)
    {
        $whereMap = [
            'user_id' => $userId,
            'account_id' => $id,
        ];
        $isHas = (new AccountUserMap())->where($whereMap)->column('id');
        if (!$isHas) {
            return [];
        }


        $join[] = ['server s', 'a.server_id = s.id', 'left'];
        $field = 'a.id,a.channel_id,a.account_code,a.account_name,a.password,a.server_id,a.site_code
        ,s.ip,s.proxy,s.user_agent,s.id as sid,s.platform_data,s.name';
        $where = [
            'a.id' => $id,
        ];
        $list = (new Account())->alias('a')->join($join)->field($field)->where($where)->find();
        $one = $list->toArray();
        $one['channel_name'] = Cache::store('Channel')->getChannelName($one['channel_id']);
        $reData = $one;
        $this->getSuperBrowserCookie($reData, $userId);


        return $this->showRetData($reData);

    }

    private function showRetData($reData)
    {
        $sites = $this->getSuperSites($reData['channel_id'], $reData['site_code']);
        $sites = $sites[0];
        $retData = [
            'env_website' => [
                'website_id' => $reData['id'], //环境id
                'website_name' => $reData['account_code'], //环境名称
                'cookie' => $reData['cookie'], //环境cookie
                'profile' => $reData['profile'], //环境缓存文件
                'proxy_id' => $this->getProxy($reData), //代理id
                'ip' => $reData['ip'], //代理IP
                'tags' => [], //所属标签，数组
                'browser_custome' => $this->getBrowserCustome($reData), //所属标签，数组
                'init_url' => [], //所属标签，数组
            ],
            'websites' => [
                [
                    'site_id' => $sites['site_id'],
                    'site_name' => $sites['site_name'],
                    'website_username' => $reData['account_name'], //店铺账号
                    'website_passwd' => $reData['password'], //店铺密码
                    'url' => '', //登录url
                    'url_name' => '', //登录url名称
                ],
            ],
        ];


        return $retData;
    }

    /**
     * 拉取超级浏览器的cookie信息 并格式化显示
     * @param $data
     * @param $userId
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function getSuperBrowserCookie(&$data, $userId)
    {
        $where = [
//            'channel_id' => $data['channel_id'],
            'user_id' => $userId,
            'account_id' => $data['id'],
//            'server_id' => $data['server_id'],
        ];
        $info = (new ServerUserAccountInfo())->isHas($where);
        $data['cookie'] = $info['cookie'] ?? '';
        $data['profile'] = $info['profile'] ?? '';
        $data['platform_data'] = $data['platform_data'] ?? '';
        $encryption = new Encryption();
        $data['password'] = $encryption->decrypt($data['password']);
    }


    public function getExtranetType()
    {
        $lists = $this->getExtranets();
        $reData = [];
        foreach ($lists as $v) {
            $reData[] = [
                'value' => $v['id'],
                'label' => $v['name'],
            ];
        }
        return $reData;
    }

    public function getExtranets($where = [], $field = '*')
    {
        $lists = (new ExtranetType())->field($field)->where($where)->order('id')->select();
        return $lists;
    }

    /**
     * 获取服务器DNS信息
     * @param $computer
     * @param $ip
     * @param $mac
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getServerDns($computer, $ip, $mac)
    {
        $reData = [];
        $where = [
            'name' => $computer,
            'ip' => $ip,
            'mac' => $mac,
        ];
        $field = 'type,ip_type';
        $server = $this->serverModel->field($field)->where($where)->find();
        if ($server) {
            if ($server['type'] != 0 || $server['ip_type'] == 0) {
                return $reData;
            }
            $reData = ExtranetType::getDNS($server['ip_type']);
        }
        return $reData;
    }

    /**
     * 设置服务器用户授权[批量、队列处理]
     * @param $server_id
     * @param array $addUser
     * @param array $deleteUser
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function setAuthorizationAll($server_id, array $addUser, array $deleteUser = [], $user = [], $isRun = false)
    {
        $encryption = new Encryption();
        $server = $this->serverModel->field('id,ip,admin,password,type')->where(['id' => $server_id])->find();
        if (empty($server)) {
            return;
        }
        $server = $server->toArray();
        $server['password'] = $encryption->decrypt($server['password']);
        $addUserData = [];
        $deleteUserList = [];
        //查询出删除的用户名
        if (!empty($deleteUser)) {
            $where['server_id'] = ['eq', $server_id];
            $where['user_id'] = ['in', $deleteUser];
            $deleteUserList = (new ServerUserMap())->field('user_id,username')->where($where)->select();
        }
        //新增
        $tempAll = [];
        $updateAll = [];
        foreach ($addUser as $k => $user_id) {
            $temp = [];
            //获取用户信息
            $userInfo = Cache::store('user')->getOneUser($user_id);
            //查询数据库
            $userMapInfo = $this->serverUserMapModel->field('password')->where(['user_id' => $user_id])->find();
            if (!empty($userMapInfo)) {
                $temp['password'] = $encryption->decrypt($userMapInfo['password']);
                $password = $temp['password'];
            } else {
                $password = $encryption->createPassword(8);
                $temp['password'] = $password;
            }
            $temp['username'] = $this->userPrefix . ($userInfo['job_number'] ?? '');
            if (!checkStringIsBase64($temp['password'])) {
                $temp['password'] = $encryption->encrypt($temp['password']);
            }
            $temp['user_id'] = $user_id;
            $temp['server_id'] = $server_id;
            $temp['create_time'] = time();
            if ($server['type'] == 0) {
                $temp['status'] = 0;
            }else{
                $temp['status'] = 1;
            }
            $serverMapInfo = (new ServerUserMap())->where(['user_id' => $temp['user_id'], 'server_id' => $temp['server_id']])->find();
            if (empty($serverMapInfo)) {
                $tempAll[] = $temp;
            }else{
                $updateAll[] = [
                    'server_id' => $server_id,
                    'user_id' => $user_id,
                ];
            }
            $temp['password'] = $password;
            array_push($addUserData, $temp);
        }
        Db::startTrans();
        try {
            if (!empty($deleteUser)) {
                (new ServerUserMap())->where($where)->delete();
                ServerLog::addLog($server_id, ServerLog::user, [], $deleteUser, '', $user);
            }
            if ($tempAll) {
                (new ServerUserMap())->allowField(true)->isUpdate(false)->saveAll($tempAll);
            }

            if ($addUserData) {
                $newIds = array_column($addUserData, 'user_id');
                ServerLog::addLog($server_id, ServerLog::user, $newIds, [], '', $user);
            }
            if ($server['type'] == 0) {
                foreach ($updateAll as $v){
                    (new ServerUserMap())->save(['status' => 0],$v);
                }
                $params = $this->remoteProcessNew($server, $deleteUserList, $addUserData);
                if($isRun){ // 是否立刻发送
                    (new ManagerServer())->sendServer($params['server'], $params['userAd']);
                }else{
                    (new UniqueQueuer(ServerUserSendQueue::class))->push($params);
                }
            }
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            throw new Exception($e->getMessage());
        }
    }


    /**
     * 组装 远程处理服务器用户信息
     * @param array $server
     * @param array $deleteUser
     * @param array $addUser
     * @return bool|mixed
     * @throws Exception
     */
    public function remoteProcessNew(array $server, array $deleteUser, array $addUser)
    {

        // 只有类型为虚拟机的服务器才去创建用户
        if ($server['type'] != 0) {
            return true;
        }
        $userAd = [];
        //删除
        foreach ($deleteUser as $k => $user) {
            $userAd[] = [
                'handle_Type' => 0, //handle_Type 1（新增或修改），0（删除）
                'local_password' => '',
                'local_username' => str_replace(' ', '', $user['username']),
                'user_id' => $user['user_id'],
            ];
        }
        //新增
        foreach ($addUser as $k => $user) {
            $userAd[] = [
                'handle_Type' => 1, //handle_Type 1（新增或修改），0（删除）
                'local_password' => $user['password'],
                'local_username' => str_replace(' ', '', $user['username']),
                'user_id' => $user['user_id'],
            ];
        }
        return [
            'server' => $server,
            'userAd' => $userAd,
        ];

    }


    /** @var string 测试站Url */
    private $test_url = 'http://172.18.8.242';

    /** @var string 正试站接收url */
    private $callback_url = 'http://api.rondaful.com:8081';

    private $post_url = '/post?url=serverUserUpdate';

    /**
     * 接收请求
     * @param $server
     * @param $userAd
     * @return bool
     * @throws Exception
     */
    public function sendServer($server, $userAd)
    {
        $bind_ip = $server['ip'];
        $admin = $server['admin'];
        $adminPass = $server['password'];
        $callBaskUrl = $this->callback_url . $this->post_url;
        try {
            if (empty($adminPass)) {
                throw new Exception('请先设置服务器管理员密码');
            }
            $url = 'http://' . $bind_ip . ':10088/user_management';
            $data = [
                'user_Name' => $admin,
                'user_Password' => $adminPass,
                'callBackUrl' => $callBaskUrl,
                'server_id' => $server['id'],
            ];
            $data['userAd'] = $userAd;
            Cache::handler()->hSet('hash:sendServer:' . date('Ymd') . ':' . date('H'), date('Y-m-d H:i:s'), $url . '--' . json_encode($data));
//            var_dump($data);die;
            $result = $this->sendCommand($url, $data, false);
            Cache::handler()->hSet('hash:sendServer:result' . date('Ymd') . ':' . date('H'), date('Y-m-d H:i:s'), json_encode($result));
            $result = json_decode($result, true);
            if (isset($result['status']) && $result['status'] == 'Success') { //请求成功
                return true;
            } else {
                $msg = '服务器【' . $bind_ip . '】设置用户失败,通讯失败';
                ServerLog::addLog($server['id'], ServerLog::delete, $userAd, [], $msg);
                throw new Exception($msg);
            }
        } catch (Exception $e) {
            $message = '【设置用户失败】
            '.$e->getMessage();
            $this->sendMessageGroup($message);
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 软件回调服务器状态
     * @param $serverId
     * @param $userData
     * @throws Exception
     */
    public function serverReceive($serverId, $userData)
    {
        $userInfo = [
            'user_id' => 0,
            'realname' => '[软件回调]',
        ];
        $tempAll = [];
        $msg = '';
        $allStatus = ['', '成功', '失败'];
        foreach ($userData as $user) {
            $name = Cache::store('User')->getOneUserRealname($user['user_id']);
            if ($user['handle_Type'] == 1) { // 新增的
                $temp = [];
                $temp['user_id'] = $user['user_id'];
                $temp['server_id'] = $serverId;
                $tempAll[] = [
                    'where' => $temp,
                    'save' => ['status' => $user['status']],
                ];
                $msg .= '[新增]' . $name . '状态【' . $allStatus[$user['status']] . '】，';
            } else {
                $msg .= '[删除]' . $name . '状态【' . $allStatus[$user['status']] . '】，';
            }
        }
        Db::startTrans();
        try {
            foreach ($tempAll as $v) {
                (new ServerUserMap())->save($v['save'], $v['where']);
            }
            ServerLog::addLog($serverId, ServerLog::user, [], [], $msg, $userInfo);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            $msg = '更新失败' . $e->getMessage();
            ServerLog::addLog($serverId, ServerLog::delete, $userData, [], $msg, $userInfo);
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 读取excel添加服务器管理数据和日志
     * @return string
     * @throws Exception
     */
    public function insertServerByExcel()
    {
        //读取指定文件夹里面的excel
        Db::startTrans();
        try {
            $model = new ExtranetType();
            $fileName = ROOT_PATH . 'public/upload/云20190304.xls';
            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
            $data = $this->readExcel($fileName, $ext);
            $userInfo = [
                'user_id' => 0,
                'realname' => '[系统自动]',
            ];
            foreach ($data as $key => $val) {
                if ($key == 0 && $val['A'] == '外网类型') {
                    continue;
                }
                if (empty($val['A'])) {
                    break;
                }
                $in_type = $model->where(['name' => $val['A']])->value('id');
                //定义新增数组
                $upload = [
                    'name' => $val['F'],
                    'ip' => $val['E'],
                    'type' => 0,
                    'ip_type' => $in_type,
                    'network_ip' => $val['D'],
                    'create_time' => time(),
                ];
                //添加数据获取id
                $server_id = $this->serverModel->insertGetId($upload);
                //添加日志数据
                $msg = '【服务器名称】增加:' . $val['F'] . '【外网ip地址】增加:' . $val['D'] . '【外网类型】增加:' . $val['A']
                    . '【ip地址】增加:' . $val['E'] . ';';
                ServerLog::addLog($server_id, ServerLog::add, [], [], $msg, $userInfo);
            }
            Db::commit();
            return 'success';
        } catch (Exception $e) {
            Db::rollback();
            throw new Exception($e->getMessage() . $e->getFile() . $e->getLine());
        }
    }

    public function readExcel($path, $ext = 'xlsx')
    {
        Loader::import('phpExcel.PHPExcel', VENDOR_PATH);

        switch ($ext) {
            case 'xlsx':
                $reader = \PHPExcel_IOFactory::createReader('Excel2007'); //设置以Excel5格式(Excel97-2003工作簿)
                break;
            case 'xls':
                $reader = \PHPExcel_IOFactory::createReader('Excel5'); //设置以Excel5格式(Excel97-2003工作簿)
                break;
            case 'csv':
                $reader = \PHPExcel_IOFactory::createReader('csv'); //设置以Excel5格式(Excel97-2003工作簿)
                break;
            default:
                $reader = \PHPExcel_IOFactory::createReader('Excel2007'); //设置以Excel5格式(Excel97-2003工作簿)
                break;
        }

        $PHPExcel = $reader->load($path); // 载入excel文件
        $sheet = $PHPExcel->getSheet(0); // 读取第一個工作表
        $highestRow = $sheet->getHighestRow(); // 取得总行数
        $highestColumm = $sheet->getHighestColumn(); // 取得总列数

        $data = [];
        /** 循环读取每个单元格的数据 */
        for ($row = 1; $row <= $highestRow; $row++)    //行号从1开始
        {
            $dataset = [];
            for ($column = 'A'; $column <= $highestColumm; $column++)  //列数是以A列开始
            {
                $dataset[$column] = (string)$sheet->getCell($column . $row)->getValue();
            }
            $data[] = $dataset;
        }
        return $data;
    }

    public function checkServer($server_id)
    {
        $model = new Server();
        $old = $model->where('id', $server_id)
            ->find();
        if (!$old) {
            throw new Exception('当前服务器不存在，无法绑定');
        }
        if ($old['status'] == 1) {
            throw new Exception('当前服务器已停用，无法绑定');
        }
    }

    private function getCanUseWhere($param)
    {
        $o = new Server();
        $o = $o->where('status', 0);
        if (!empty($param['name'])) {
            $o = $o->where('name|ip', 'like', '%' . $param['name'] . '%');
        }
        if (isset($param['channel_id']) && $param['channel_id']) {
            $o->where('id','exp','not in ( select server_id from account where status != 6 and server_id>0 and channel_id= ' . $param['channel_id'] . ' UNION ALL select server_id from account_apply   where  server_id>0 and status not in (4,5,6) and channel_id = ' . $param['channel_id'] . ' )  ');
        }
        return $o;
    }

    public function getCanUse($page, $pageSize, $param)
    {
        $result = ['list' => []];
        $result['page'] = $page;
        $result['pageSize'] = $pageSize;
        $result['count'] = $this->getCanUseWhere($param)->count();
        if ($result['count'] == 0) {
            return $result;
        }
        $extranet_type = (new ExtranetType())->field(true)->column('name', 'id');
        $extranet_type[0] = '';
        $o = $this->getCanUseWhere($param);
        $ret = $o->page($page, $pageSize)
            ->field(true)
            ->order('id desc')->select();
        if ($ret) {
            foreach ($ret as $value){
                $value['server_type'] = $value['type'];
                switch ($value['type']) {
                    case 0:
                        $value['type'] = '虚拟机' . '(' . ($extranet_type[$value['ip_type']]) . ')';
                        break;
                    case 1:
                        $value['type'] = '云服器';
                        break;
                    case 2:
                        $value['type'] = '超级浏览器';
                        break;
                    case 3:
                        $value['type'] = '代理';
                        break;
                }
                $value['use'] = $serverUseCount[$value['id']] ?? 0;
            }
            $result['data'] = $ret;
        }
        return $result;
    }

    public function updageSuperServer()
    {
        $api = new SuperBrowserBaseApi();
        $re = $api->getIpList();
        $model = new Server();
        $ips = array_column($re,'ip');
        $where = [
            'type' => 2,
            'ip' => ['in' ,$ips],
        ];
        $oldId = $model->where($where)->column('ip','id');
        foreach ($re as $v){
            if(in_array($v['ip'],$oldId)){

            }


        }
    }

    /**
     * 再次发起服务器信息更新用户请求
     * @param $id
     * @param $userIds
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function againUsers($id, $userIds)
    {
        $user = Common::getUserInfo();
        $user['realname'] = '[再次请求]'.$user['realname'];
        try {
            $this->setAuthorizationAll($id, $userIds, [], $user, true);
        } catch (Exception $e) {
            throw new JsonErrorException($e->getMessage());
        }
        return $this->authorizationInfo($id);
    }

    /**
     * 批量修改状态
     * @param $ids
     * @param int $status
     * @return bool
     */
    public function changeBatchStatus($ids, $status = 0)
    {
        try{
            foreach ($ids as $id){
                $data = [
                    'id' => $id,
                    'status' => $status,
                ];
                $this->changeStatus($data);
            }
        }catch (Exception $e){
            throw new JsonErrorException($e->getMessage());
        }
        return true;
    }

}