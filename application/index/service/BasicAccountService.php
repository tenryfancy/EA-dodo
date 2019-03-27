<?php

namespace app\index\service;

use app\common\exception\JsonErrorException;
use app\common\model\Account;
use app\common\model\AccountApply;
use app\common\model\AccountLog;
use app\common\model\AccountPhoneHistory;
use app\common\model\AccountUserMap;
use app\common\model\aliexpress\AliexpressAccount;
use app\common\model\amazon\AmazonAccount;
use app\common\model\cd\CdAccount;
use app\common\model\daraz\DarazAccount;
use app\common\model\ebay\EbayAccount;
use app\common\model\fummart\FummartAccount;
use app\common\model\joom\JoomAccount;
use app\common\model\jumia\JumiaAccount;
use app\common\model\lazada\LazadaAccount;
use app\common\model\newegg\NeweggAccount;
use app\common\model\oberlo\OberloAccount;
use app\common\model\pandao\PandaoAccount;
use app\common\model\paytm\PaytmAccount;
use app\common\model\shopee\ShopeeAccount;
use app\common\model\shoppo\ShoppoAccount;
use app\common\model\umka\UmkaAccount;
use app\common\model\User;
use app\common\model\vova\VovaAccount;
use app\common\model\walmart\WalmartAccount;
use app\common\model\wish\WishAccount;
use app\common\model\yandex\YandexAccount;
use app\common\model\zoodmall\ZoodmallAccount;
use app\common\service\ChannelAccountConst;
use app\common\service\Common;
use app\common\service\Encryption;
use app\common\service\UniqueQueuer;
use app\index\queue\AccountChannelBaseIdQueue;
use app\index\queue\AccountUserMapUpdateQueue;
use app\index\queue\ChannelAccountAddBatchQueue;
use app\index\validate\BasicAccountValidate;
use think\console\command\make\Model;
use think\Db;
use app\common\cache\Cache;
use app\common\service\Common as CommonService;
use think\Exception;
use think\Loader;
use app\common\traits\Export;
use app\common\service\Excel;
use app\index\service\Phone as PhoneService;
use app\index\service\AccountCompanyService;
use app\common\model\ExtranetType;
use think\db\Query;
use PDO;

Loader::import('phpExcel.PHPExcel', VENDOR_PATH);

/** 基础账号信息
 * Created by PhpStorm.
 * User: XPDN
 * Date: 2017/8/22
 * Time: 18:05
 */
class BasicAccountService
{
    protected $accountModel;
    protected $validate;

    public function __construct()
    {
        if (is_null($this->accountModel)) {
            $this->accountModel = new Account();
        }
        $this->validate = new BasicAccountValidate();
    }

    /**
     * 账号列表
     * @param $where
     * @param $page
     * @param $pageSize
     * @param $orderBy
     * @return array
     * @throws \think\Exception
     */
    public function accountList($where, $page = 1, $pageSize = 10, $orderBy = '')
    {
        $field = 'a.id,a.account_code,a.channel_id,a.account_name,a.site_code,a.phone,a.phone_id,a.email,a.email_id,a.credit_card_id,a.status,a.account_creator,a.account_create_time
        ,c.company
        ,s.name as server_name,s.ip as server_ip
        ,e.imap_url,e.smtp_url,s.type as server_type,s.ip_type
        ';

        $join[] = ['account_company c', 'c.id = a.company_id', 'left'];
        $join[] = ['server s', 'a.server_id = s.id', 'left'];
        $join[] = ['email_server e', 'a.email_server_id = e.id', 'left'];

        $count = $this->accountModel->alias('a')->field($field)->where($where)->join($join)->count();
        $accountList = $this->accountModel->alias('a')->field($field)->where($where)->join($join)->order($orderBy)->page($page, $pageSize)->select();
        $extranet_type= (new ExtranetType())->field(true)->column('name','id');
        $extranet_type[0] = '';

        foreach ($accountList as $key => &$value) {
            $user = Cache::store('user')->getOneUser($value['account_creator']);
            $value['account_creator'] = $user['realname'] ?? '';
            $value['status_name'] = $this->accountModel->statusName($value['status']);
            $value['channel_id'] = !empty($value['channel_id']) ? Cache::store('channel')->getChannelName($value['channel_id']) : '';
            $value['phone'] = $this->getPhoneName($value['phone_id']);
            $value['email'] = $this->getEmailName($value['email_id']);
            $value['credit_card'] = $this->getCreditCardName($value['credit_card_id']);
            $server_type_txt = $extranet_type[$value['ip_type']]??'';
            switch ($value['server_type'] )
            {
                case 0:
                    $value['server_type_txt']= $server_type_txt?'虚拟机'.'('.($extranet_type[$value['ip_type']]).')':'虚拟机';
                    break;
                case 1:
                    $value['server_type_txt']='云服器';
                    break;
                case 2:
                    $value['server_type_txt']='超级浏览器';
                    break;
                case 3:
                    $value['server_type_txt']='代理';
                    break;
            }
        }
        $result = [
            'data' => $accountList,
            'page' => $page,
            'pageSize' => $pageSize,
            'count' => $count,
        ];
        return $result;
    }

    public function getPhoneName($id)
    {
        $name = (new \app\common\model\Phone())->where('id', $id)->value('phone');
        return $name ? $name : '';
    }

    public function getEmailName($id)
    {
        $name = (new \app\common\model\Email())->where('id', $id)->value('email');
        return $name ? $name : '';
    }

    public function getCreditCardName($id){
        $name = (new \app\common\model\account\CreditCard())->where('id', $id)->value('card_number');
        return $name ? $name : '';
    }

    /**
     * 状态信息列表
     * @return array
     */
    public function statusInfo()
    {
        $status = [];
        $statusList = Account::STATUS;
        foreach ($statusList as $key => $name) {
            $status[$key] = [
                'status' => $key,
                'remark' => $name,
            ];
        }
        return $status;
    }

    /** 保存账号信息
     * @param $data
     * @return array
     */
    public function save($data)
    {
        $data['create_time'] = time();
        $data['update_time'] = time();
//        $data['company_time'] = strtotime($data['company_time']);
        $data['account_create_time'] = strtotime($data['account_create_time']);
//        if (($result = $this->accountModel->isHas($data['channel_id'], $data['server_id']))) {
//            throw new JsonErrorException('该服务器IP已经被其他账号绑定了', 400);
//        }
        $encryption = new Encryption();
        //密码加密
        $data['password'] = $encryption->encrypt($data['password']);

        if (isset($data['password_minor'])) {
            $data['password_minor'] = $encryption->encrypt($data['password_minor']);
        }
        if (isset($data['email_password'])) {
            $data['email_password'] = $encryption->encrypt($data['email_password']);
        }

        $data['account_code'] = strtolower($data['account_code']);
        if ($data['site_code']) {
            $data['site_code'] = json_decode($data['site_code'], true);
            $data['site_code'] = implode(',', $data['site_code']);
        }
        $isHasCode = $this->accountModel->where('account_code', $data['account_code'])->value('account_code');
        if ($isHasCode) {
            throw new JsonErrorException('该简称已经被使用', 400);
        }
        if (!empty($data['phone_id'])) {
            $this->checkPhone($data['phone_id'], $data['channel_id']);
            $data['phone'] = $this->getPhoneName($data['phone_id']);
        }
        if (!empty($data['email_id'])) {
            $Email = new Email();
            $Email->checkEmail($data['email_id']);
            $data['email'] = $this->getEmailName($data['email_id']);
        }

//        if (!$this->validate->check($data)) {
//            throw new JsonErrorException($this->validate->getError(), 500);
//        }
        Db::startTrans();
        try {
            $this->accountModel->isUpdate(false)->save($data);
            //获取最新的数据返回
            $new_id = $this->accountModel->id;
            AccountLog::addLog($new_id, AccountLog::add, $data);
            Db::commit();
            return $new_id;
        } catch (\Exception $e) {
            Db::rollback();
            throw new JsonErrorException($e->getMessage(), 500);
        }
    }

    /** 账号信息
     * @param $id
     * @return array|false|\PDOStatement|string|\think\Model
     */
    public function read($id)
    {
        $accountInfo = $this->accountModel->where(['id' => $id])->find();
        if (empty($accountInfo)) {
            throw new JsonErrorException('账号不存在', 500);
        }
        if ($accountInfo['collection_account'] == 'null') {
            $accountInfo['collection_account'] = [];
        } else {
            $accountInfo['collection_account'] = json_decode($accountInfo['collection_account'], true);
        }
        $serverService = new ManagerServer();
        $accountInfo['server_name'] = '';
        if (!empty($accountInfo['server_id'])) {
            $serverInfo = $serverService->info($accountInfo['server_id']);
            $accountInfo['server_name'] = $serverInfo['ip'] . '(' . $serverInfo['name'] . ')';
        }

        if (!empty($accountInfo['site_code'])) {
            if (strpos($accountInfo['site_code'], ',') !== false) {
                $accountInfo['site_code'] = explode(',', $accountInfo['site_code']);
            } else {
                $accountInfo['site_code'] = [$accountInfo['site_code']];
            }
        }

        $accountInfo['account_creator_name'] = Cache::store('user')->getOneUser($accountInfo['account_creator'])['realname'] ?? '';
        $accountInfo['use_account_name'] = Cache::store('user')->getOneUser($accountInfo['use_account_id'])['realname'] ?? '';
        $accountInfo['collection_msg'] = $accountInfo['collection_msg'] ? json_decode($accountInfo['collection_msg'], true) : [];

        $accountInfo['phone'] = $this->getPhoneName($accountInfo['phone_id']);
        $accountInfo['email'] = $this->getEmailName($accountInfo['email_id']);
        $accountInfo['credit_card'] = $this->getCreditCardName($accountInfo['credit_card_id']);

        return $accountInfo;
    }

    public function getPhoneLog($id)
    {
        return AccountPhoneHistory::getLog($id);
    }

    /** 更新
     * @param $id
     * @param $data
     * @return mixed
     */
    public function update($id, $data)
    {
//        $data['company_time'] = strtotime($data['company_time']);
        $data['account_create_time'] = strtotime($data['account_create_time']);
//        if (($result = $this->accountModel->isHas($data['channel_id'], $data['server_id'], $id))) {
//            throw new JsonErrorException('该服务器IP已经被其他账号绑定了', 400);
//        }
        if (!$this->validate->scene('edit')->check($data)) {
            throw new JsonErrorException($this->validate->getError(), 500);
        }

        $accountInfo = $this->accountModel->where(['id' => $id])->find();
        if (empty($accountInfo)) {
            throw new JsonErrorException('记录不存在');
        }
        $accountInfo = $accountInfo->toArray();

        $encryption = new Encryption();
        //密码加密
        if (isset($data['password']) && !empty($data['password']) && $accountInfo['password'] != $data['password']) {
            $data['password'] = $encryption->encrypt($data['password']);
        }
        if (isset($data['password_minor']) && !empty($data['password_minor']) && $accountInfo['password_minor'] != $data['password_minor']) {
            $data['password_minor'] = $encryption->encrypt($data['password_minor']);
        }
        if (isset($data['email_password']) && !empty($data['email_password']) && $accountInfo['email_password'] != $data['email_password']) {
            $data['email_password'] = $encryption->encrypt($data['email_password']);
        }

        if ($data['site_code']) {
            $data['site_code'] = json_decode($data['site_code'], true);
            $data['site_code'] = implode(',', $data['site_code']);
        }

        $data['account_code'] = strtolower($data['account_code']);
        $isHasCode = $this->accountModel->where('account_code', $data['account_code'])->value('id');
        if ($isHasCode && $isHasCode != $id) {
            throw new JsonErrorException('该简称已经被使用', 400);
        }
        $oldPhong = '';
        if (isset($data['phone_id']) && $data['phone_id']) {
            $data['phone'] = $this->getPhoneName($data['phone_id']);
            if ($accountInfo['phone_id'] != $data['phone_id']) {
                $this->checkPhone($data['phone_id'], $accountInfo['channel_id']);
                $oldPhong = $this->getPhoneName($accountInfo['phone_id']);
            }

        }
        $Email = new Email();
        if (isset($data['email_id']) && $data['email_id']) {
            if ($accountInfo['email_id'] != $data['email_id']) {
                $Email->checkEmail($data['email_id']);
            }
            $data['email'] = $this->getEmailName($data['email_id']);
        }


        Db::startTrans();
        try {
            //如果是更换了服务器的，需要把 账号成员全部删除
            if (isset($data['server_id']) && !empty($data['server_id']) && $accountInfo['server_id'] != $data['server_id']) {
                $userLists = (new AccountUserMap())->where(['account_id' => $id])->column('user_id');
                $users = Common::getUserInfo();
                $users['realname'] = '[更换服务器]' . $users['realname'];
                (new ManagerServer())->setAuthorizationAll($data['server_id'], $userLists,[] ,$users);
                (new ManagerServer())->setAuthorizationAll($accountInfo['server_id'], [], $userLists,$users);
            }
            $data['update_time'] = time();
            //删除该账号之前绑定的其他服务器信息
            $updateService = ['server_id' => 0];
            $data = array_merge($updateService, $data);
            $flag = $this->accountModel->save($data, ['id' => $id]);
            if (isset($data['phone_id']) && $data['phone_id'] && $flag) {
                if ($accountInfo['phone_id'] != $data['phone_id']) {
                    $PhoneService = new PhoneService();
                    if ($data['phone_id']) {
                        $flag = $PhoneService->bind($data['phone_id']);
                        if (!$flag) {
                            throw new Exception('手机号绑定失败，请检查是否被使用');
                        }
                    }
                    if ($accountInfo['phone_id']) {
                        $PhoneService->unbind($accountInfo['phone_id']);
                    }
                }
            }
            if (isset($data['email_id']) && $data['email_id'] && $flag) {
                if ($accountInfo['email_id'] != $data['email_id']) {
                    if ($data['email_id']) {
                        $flag = $Email->bind($data['email_id']);
                        if (!$flag) {
                            throw new Exception('邮箱号绑定失败，请检查是否被使用');
                        }
                    }
                    if ($accountInfo['email_id']) {
                        $Email->unbind($accountInfo['email_id']);
                    }
                }
            }

            //添加更换记录
            if ($oldPhong) {
                AccountPhoneHistory::add($id, $oldPhong);
            }

            AccountLog::addLog($id, AccountLog::update, $data, $accountInfo);
            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 500);
        }
    }

    /**
     * @title 检测手机号是否可用
     * @param $phone_id
     * @param $channel_id
     * @throws Exception
     * @author starzhan <397041849@qq.com>
     */
    public function checkPhone($phone_id, $channel_id)
    {
        /**
         * 一个手机号只能被一个帐号绑定
         */
        $phoneService = new PhoneService();
        $phoneService->checkPhone($phone_id);
    }


    /**
     * 状态
     * @param $ids
     * @param $data
     * @param $type
     * @return bool
     */
    public function status($ids, $data, $type)
    {
        $reData = [];
        try {
            switch ($type) {
                case 'update':
                    foreach ($ids as $id) {
                        Db::startTrans();
                        try {
                            $accountInfo = $this->accountModel->where('id', $id)->find();
                            $data['update_time'] = time();
                            if ($data['status'] == Account::status_connect) {
                                $this->createChannelAccount([$id]);
                                $data['fulfill_time'] = time();
                            }
                            $this->accountModel->where('id', $id)->update($data);
                            AccountLog::addLog($id, AccountLog::update, $data, $accountInfo);
                            Db::commit();
                        } catch (Exception $e) {
                            Db::rollback();
                            $reData[] = $e->getMessage() . $e->getFile() . $e->getLine();
                        }
                    }
                    break;
            }
        } catch (\Exception $e) {
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 400);
        }
        return $reData;
    }

    /**
     * 添加平台账号信息[自动回写注册]
     * @param $ids
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function createChannelAccount($ids)
    {
        $list = $this->accountModel->where('id', 'in', $ids)->select();

        foreach ($list as $item) {
            $code = strtolower($item['account_code']);
            $account  = [
                'account_id' => $item['id'], //账号基础资料ID
                'account_name' => $item['account_name'], //账号全称
                'code' => $code, //账号简称
                'create_time' => time(), // 创建时间
                'created_user_id' => $item['creator_id'], // 创建用户ID
                'email' => $item['email'], // 账号邮箱信息[可能为空字符串]
                'phone' => $item['phone'], //手机号码
            ];
            $siteCode = $this->getAccountSite($item['site_code']);
            (new \app\index\service\ChannelAccount())->createChannelAccount($account, $siteCode, $item['channel_id']);
        }
        return true;
    }

    /**
     * 替换添加元素的某个属性
     * @param $add
     * @param string $newKey
     * @param string $oldKey
     */
    private function changeKey(&$add, $newKey = 'updated_time', $oldKey = 'update_time')
    {
        $add[$newKey] = $add[$oldKey];
        unset($add[$oldKey]);
    }

    /**
     * 检查是否存在code
     * @param $channelId
     * @param $code
     * @param string $site
     * @return bool
     */
    public static function isHasCode($channelId, $code, $site = '')
    {
        if (!$channelId || !$code) {
            throw new JsonErrorException('该账号简称缺少必要参数channelId、code');
        }
        $where['status'] = ['<>', 5];
        $where['channel_id'] = $channelId; // 平台直接也不可以重复
        switch ($channelId) {
            case ChannelAccountConst::channel_amazon:
                if (!$site) {
                    throw new JsonErrorException('该账号简称缺少必要参数站点');
                }
                $where['account_code'] = $code;
                $siteCode = (new Account())->where($where)->value('id');
                if($siteCode){
                    return true;
                }
                $code = substr($code, 0, -2);
                $ok = (new BasicAccountService())->checkSiteCode($channelId,$code . 'uk',$site);
                if($ok){
                    return $ok;
                }
                $ok = (new BasicAccountService())->checkSiteCode($channelId,$code . 'us',$site);
                if($ok){
                    return $ok;
                }
                $ok = (new BasicAccountService())->checkSiteCode($channelId,$code . 'jp',$site);
                if($ok){
                    return $ok;
                }
                break;
            case ChannelAccountConst::channel_Lazada:
            case ChannelAccountConst::channel_Shopee:
            case ChannelAccountConst::channel_Daraz:
                $code = substr($code, 0, -2);
                $ok = (new BasicAccountService())->checkSiteCode($channelId,$code,$site);
                if($ok){
                    return $ok;
                }
                break;
            default:
                $where['account_code'] = $code;
                $isHas = (new Account())->where($where)->value('account_code');
                if ($isHas) {
                    return true;
                }
        }

        throw new JsonErrorException('该账号简称不存在与账号基础资料里，无法添加');

    }

    /**
     * @param $channelId
     * @param $code
     * @param $site
     * @return array|bool|false|\PDOStatement|string|\think\Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function checkSiteCode($channelId,$code,$site)
    {
        $accountInfo = (new Account())->field('id,site_code')->where(['account_code' => $code, 'channel_id' => $channelId])->find();
        if($accountInfo){
            $allsite = explode(',',$accountInfo['site_code']);
            if($allsite && in_array($site,$allsite)){
                return $accountInfo;
            }
        }
        return false;
    }

    /**
     * 添加新的平台账号
     * @param $model
     * @param $where
     * @param $add
     * @param string $filed
     */
    private function addNewAccount($model, $where, $add, $filed = 'code')
    {
        $isHas = $model->where($where)->value($filed);
        if (!$isHas) {
            $model->isUpdate(false)->save($add);
        }
    }

    /**
     * 查看密码
     * @param $password
     * @param $account_id
     * @param $type
     * @return bool|string
     */
    public function viewPassword($password, $account_id, $type)
    {
        $enablePassword = '';
        $user = CommonService::getUserInfo();
        if (empty($user)) {
            throw new JsonErrorException('非法操作', 400);
        }
        $userModel = new User();
        $userInfo = $userModel->where(['id' => $user['user_id']])->find();
        if (empty($userInfo)) {
            throw new JsonErrorException('外来物种入侵', 500);
        }
        if ($userInfo['password'] != User::getHashPassword($password, $userInfo['salt'])) {
            throw new JsonErrorException('登录密码错误', 500);
        }
        $encryption = new Encryption();
        //查看账号信息
        $accountInfo = $this->accountModel->field('email_password,password,password_minor')->where(['id' => $account_id])->find();
        if (empty($accountInfo)) {
            throw new JsonErrorException('账号记录不存在', 500);
        }
        switch ($type) {
            case 'email':
                $enablePassword = $encryption->decrypt($accountInfo['email_password']);
                break;
            case 'account':
                $enablePassword = $encryption->decrypt($accountInfo['password']);
                break;
            case 'account_minor':
                $enablePassword = $encryption->decrypt($accountInfo['password_minor']);
                break;
        }
        return $enablePassword;
    }

    /**
     * 服务器已绑定的平台账号
     * @param $channel_id
     * @param $server_id
     * @return false|\PDOStatement|string|\think\Collection
     */
    public function alreadyBind($channel_id, $server_id)
    {
        $where['channel_id'] = ['eq', $channel_id];
        $where['server_id'] = ['eq', $server_id];
        $join[] = ['account_company c', 'c.id = company_id', 'left'];
        $field = 'account_code,c.company';
        $accountList = $this->accountModel->alias('a')->join($join)->field($field)->where($where)->select();
        return $accountList;
    }

    public function export()
    {
        $encryption = new Encryption();
        $where['account_code'] = ['not in', ['58wishyang', '231wishzog', '358wishwag', '357wishwan', '361wishwag', '234wishgu']];
        $dataList = $this->accountModel->field('account_name,account_code,password,channel_id')->where(['channel_id' => 3])->where('status', '<>', 5)->where($where)->select();
        foreach ($dataList as $k => &$value) {
            $value = $value->toArray();
            $value['account_name'] = trim($value['account_name']);
            $value['account_code'] = trim($value['account_code']);
            switch ($value['channel_id']) {
                case 1:
                    $value['channel_id'] = 'ebay';
                    break;
                case 2:
                    $value['channel_id'] = 'amazon';
                    break;
                case 3:
                    $value['channel_id'] = 'wish';
                    break;
                case 4:
                    $value['channel_id'] = 'aliExpress';
                    break;
            }
            $value['password'] = $encryption->decrypt($value['password']);
        }
        //return $dataList;
        $this->export_csv($dataList);
    }

    function export_csv($data)
    {
        $string = "";
        foreach ($data as $key => $value) {
            foreach ($value as $k => $val) {
                $value[$k] = iconv('utf-8', 'GB2312//IGNORE', $value[$k]);
            }
            $string .= implode(",", $value) . "\n"; //用英文逗号分开
        }
        $filename = date('Ymd') . '.csv'; //设置文件名
        header("Content-type:text/csv");
        header("Content-Disposition:attachment;filename=" . $filename);
        header('Cache-Control:must-revalidate,post-check=0,pre-check=0');
        header('Expires:0');
        header('Pragma:public');
        echo $string;
    }

    private function getAccountSite($siteCode)
    {
        $site_code = [];
        if (strpos($siteCode, ',') !== false) {
            $site_code = explode(',', $siteCode);
        } else {
            $site_code = [$siteCode];
        }
        return $site_code;
    }

    /**
     * 拉取日志
     * @param $id
     * @return false|\PDOStatement|string|\think\Collection
     */
    public function getLog($id)
    {
        return AccountLog::getLog($id);
    }

    /**
     * 更新该目录下的文件的账号基础资料账号创建时间
     * @return array
     */
    public function saveAllDir()
    {
        set_time_limit(0);
        $path = 'download/member_ship_save/';
        $dir = ROOT_PATH . 'public/' . $path;
        $filenames = [];
        if (is_dir($dir)) {
            if ($dh = opendir($dir)) {
                while (($file = readdir($dh)) !== false) {
                    $file_arr = explode('.', $file);
                    if (isset($file_arr[1]) && in_array($file_arr[1], ['xlsx', 'xls', 'csv'])) {
                        $filename = $path . $file_arr[0] . '.' . $file_arr[1];
                        array_push($filenames, $filename);
                    }
                }
                closedir($dh);
            }
        }
        //更新
        foreach ($filenames as $filename) {
            $this->saveChannelMember($filename);
            @unlink(ROOT_PATH . 'public/' . $filename);
        }
        return $filenames;
    }

    /**
     * 更新某个文件的渠道账号人员关系表的仓库类型
     * @param $filename
     * @return bool
     * @throws Exception
     */
    public function saveChannelMember($filename)
    {
        $result = Excel::readExcel($filename);
//        $date = $this->checkAndBuildData($result); //账号基础资料账号创建时间
        $date = $this->checkAndBuildWishData($result); //账号基础资料账号wish平台的子账号与主账号信息
        return true;
    }

    /**
     * 账号基础资料账号wish平台的子账号与主账号信息
     * @param $result
     * @return array
     * @throws Exception
     */
    private function checkAndBuildWishData($result)
    {
        $model = new Account();
        //密码加密
        $encryption = new Encryption();
        $message = [];
        foreach ($result as $v) {
            $row = array_filter($v);
            if (!$row) {
                continue;
            }
            $old = $model->where('account_code', $row['简称'])->find();
            if ($old) {

                $id = $old['id'];
                try {
                    $save['account_name'] = $row['主登陆邮箱'];
                    $save['email'] = $row['主登陆邮箱'];
                    $save['email_password'] = $encryption->encrypt($row['邮箱密码']);
                    $save['password'] = $encryption->encrypt($row['登录密码']);
                    $save['account_name_minor'] = $row['子邮箱'];
                    $save['password_minor'] = $encryption->encrypt($row['子账号密码']);
                    (new Account())->save($save, ['id' => $id]);
                    AccountLog::addLog($id, AccountLog::update, $save, $old, '批量更新');
                } catch (Exception $e) {
                    $message[] = [
                        'message' => $e->getMessage(),
                        'row' => $row,
                    ];
                }
            }
        }
        if ($message) {
            throw new JsonErrorException(json_encode($message, JSON_UNESCAPED_UNICODE));
        }
        return true;
    }

    /**
     * 账号基础资料账号创建时间
     * @param $result
     * @return array
     * @throws Exception
     */
    private function checkAndBuildData($result)
    {
        $model = new Account();
        $message = [];
        foreach ($result as $v) {
            $row = array_filter($v);
            if (!$row) {
                continue;
            }
            $old = $model->where('account_code', $row['account_code'])->field('id,account_create_time')->find();
            if ($old) {
                $id = $old['id'];
                try {
                    $save['account_create_time'] = ($row['account_create_time'] - 25569) * 86400; //获得秒数
                    (new Account())->save($save, ['id' => $id]);
                    AccountLog::addLog($id, AccountLog::update, $save, $old, '批量更新');
                } catch (Exception $e) {
                    $message[] = [
                        'message' => $e->getMessage(),
                        'row' => $row,
                    ];
                }

            }
        }
        if ($message) {
            throw new JsonErrorException(json_encode($message, JSON_UNESCAPED_UNICODE));
        }
        return true;
    }

    /**
     * @title 下载
     * @param $id
     * @param $start_time
     * @param $end_time
     * @author starzhan <397041849@qq.com>
     */
    public function doCatchTransactionTotal($channel_id, $code, $start_time, $end_time, $option = [])
    {
        $data = [
            'channel' => $channel_id,
            'code' => $code,
            'start_time' => $start_time,
            'end_time' => $end_time
        ];
        Cache::store('SettleReport')->setIsRunningEnviroment($code, $data);
        $accountInfo = $this->accountModel
            ->field(true)->where(['channel_id' => $channel_id, 'account_code' => $code])->find();
        if (empty($accountInfo)) {
            throw new JsonErrorException('账号不存在', 500);
        }
        $Encryption = new Encryption();
        $accountInfo['password'] = $Encryption->decrypt($accountInfo['password']);
        $accountInfo['server_name'] = '';
        if (!empty($accountInfo['server_id'])) {
            $serverService = new ManagerServer();
            $serverInfo = $serverService->info($accountInfo['server_id']);
            $accountInfo['server_name'] = $serverInfo['ip'];
        }
        if (!$accountInfo['server_name']) {
            throw new JsonErrorException('服务器地址为空', 500);
        }
        $AccountPushToOA = new AccountPushToOA();
        $AccountPushToOA->push($accountInfo, $start_time, $end_time, $option);
    }

    /**
     * @title 注释..
     * @param $phoneId
     * @return false|\PDOStatement|string|\think\Collection
     * @author starzhan <397041849@qq.com>
     */
    public function getAccountByPhoneId($phoneId)
    {
        $accountInfo = $this->accountModel->field("id,channel_id,company_id,account_code,site_code")
            ->where('phone_id', $phoneId)
            ->select();
        return $accountInfo;
    }


    public function bindPhoneId()
    {
        $sql =
            <<<Eof
            select 
                phone,
                channel_id,
                account_creator,
                account_create_time,
                count(*) as num
            from
           account
            GROUP BY phone
Eof;
        $Q = new Query();
        $a = $Q->query($sql, [], true, true);
        echo '<style>
            table{
            border-collapse:collapse;
            border: 1px solid #000000;
            }
            td,th{
            border-collapse:collapse;
            border: 1px solid #000000;
            }
            </style>';
        echo "<table>";
        echo "<tr><th>phone</th><th>operator</th><th>create_id</th><th>create_time</th><th>status</th><th>reg_id</th><th>reg_time</th><th>account_count</th></tr>";
        while ($row = $a->fetch(PDO::FETCH_ASSOC)) {
            if (empty($row['phone'])) {
                continue;
            }
            $operator = $this->phone_check($row['phone']);
            $model = new \app\common\model\Phone();
            $data = [
                'phone' => $row['phone'],
                'operator' => $operator,
                'creator_id' => $row['account_creator'],
                'create_time' => $row['account_create_time'],
                'status' => 1,
                'reg_id' => $row['account_creator'],
                'reg_time' => $row['account_create_time'],
                'account_count' => $row['num'],
            ];
            $model->allowField(true)->isUpdate(false)->save($data);
            $newId = $model->id;
            Account::where('phone', $row['phone'])->update(['phone_id' => $newId]);
            AccountApply::where('phone', $row['phone'])->update(['phone_id' => $newId]);
            echo "<tr>";
            echo "<td>{$row['phone']}</td>";
            echo "<td>{$operator}</td>";
            echo "<td>{$row['account_creator']}</td>";
            echo "<td>{$row['account_create_time']}</td>";
            echo "<td>1</td>";
            echo "<td>{$row['account_creator']}</td>";
            echo "<td>{$row['account_create_time']}</td>";
            echo "<td>{$row['num']}</td>";
            echo "</tr>";
        }

    }

    public function luPostOff()
    {
        $sql =
            <<<Eof
            SELECT
                email,
                email_password,
                email_allowed_receive,
                email_allowed_send,
                account_creator,
                account_create_time,
                phone_id,
                channel_id,
                count(*) AS num
            FROM
                account
            GROUP BY
                email
Eof;
        $Q = new Query();
        $a = $Q->query($sql, [], true, true);
        $result = [];
        while ($row = $a->fetch(PDO::FETCH_ASSOC)) {
            if (empty($row['email'])) {
                continue;
            }
            $arr = explode("@", $row['email']);
            if (count($arr) != 2) {
                continue;
            }
            $post = $arr[1];
            $result[$post][] = $row;
        }
        $AccountCompanyService = new AccountCompanyService();
        foreach ($result as $post => $emails) {
            $postData = [];
            $postData['post'] = $post;
            $postData['email_count'] = count($emails);
            $postData['imap_url'] = '';
            $postData['imap_port'] = 0;
            $postData['smtp_url'] = '';
            $postData['smtp_port'] = 0;
            $postData['status'] = 1;
            $postData['creator_id'] = $emails[0]['account_creator'];
            $postData['create_time'] = $emails[0]['account_create_time'];
            $model = new \app\common\model\Postoffice();
            $model->allowField(true)->isUpdate(false)->save($postData);
            $postId = $model->id;
            foreach ($emails as $emailInfo) {
                $emailData = [];
                $emailData['email'] = $emailInfo['email'];
                $emailData['password'] = $emailInfo['email_password'];
                $emailData['post_id'] = $postId;
                $emailData['phone_id'] = $emailInfo['phone_id'];
                $emailData['reg_id'] = $emailInfo['account_creator'];
                $emailData['reg_time'] = $emailInfo['account_create_time'];
                $emailData['status'] = 1;
                $emailData['is_receive'] = $emailInfo['email_allowed_receive'];
                $emailData['is_send'] = $emailInfo['email_allowed_send'];
                $emailData['create_time'] = $emailInfo['account_create_time'];
                $emailData['creator_id'] = $emailInfo['account_creator'];
                $emailData['channel'] = $AccountCompanyService->placeToChannel($emailInfo['channel_id']);
                $emailData['account_count'] = $emailInfo['num'];
                $emailModel = new \app\common\model\Email();
                $emailModel->allowField(true)->isUpdate(false)->save($emailData);
                $emailId = $emailModel->id;
                Account::where('email', $emailInfo['email'])->update(['email_id' => $emailId]);
                AccountApply::where('email', $emailInfo['email'])->update(['email_id' => $emailId]);
            }

        }
    }

    public function phone_check($phone)
    {
        $isChinaMobile = "/^134[0-8]\d{7}$|^(?:13[5-9]|147|15[0-27-9]|178|18[2-478])\d{8}$/"; //移动方面最新答复
        $isChinaUnion = "/^(?:13[0-2]|145|15[56]|176|18[56])\d{8}$/"; //向联通微博确认并未回复
        $isChinaTelcom = "/^(?:133|153|177|173|18[019])\d{8}$/"; //1349号段 电信方面没给出答复，视作不存在
        // $isOtherTelphone = "/^170([059])\\d{7}$/";//其他运营商
        if (preg_match($isChinaMobile, $phone)) {
            return 2;
        } else if (preg_match($isChinaUnion, $phone)) {
            return 3;
        } else if (preg_match($isChinaTelcom, $phone)) {
            return 1;
        } else {
            return '0';
        }
    }

    /**
     * 回写所以平台的base_account_id
     * @throws Exception
     */
    public function writeBackBackAccountId()
    {
        $channel = Cache::store('Channel')->getChannel();
        foreach ($channel as $v){
            (new UniqueQueuer(AccountChannelBaseIdQueue::class))->push($v['id']);
        }
        echo 'ok';
    }

    /**
     * 回写某个平台的base_account_id
     * @param $channel
     * @throws \Exception
     */
    public function updateBaseAccount($channel)
    {
        $service = $this->getServerModel($channel);

        if($service){
            $accountUserMapService = new AccountUserMapService();
            $saveAll = [];
            $list = $service->column('code','id');
            foreach ($list as $id => $code){
                try{
                    $accountInfo = $accountUserMapService->getAccountInfo($code, $channel);
                    $saveAll[] = [
                        'id' => $id,
                        'base_account_id' => $accountInfo['id'],
                    ];
                }catch (\Exception $e){

                }
            }
            $service->isUpdate(true)->saveAll($saveAll);
        }
    }

    public function getBasicAccountOtherId($channelId,$accountId)
    {
        //1.查绑定了的账号基础资料ID
        switch ($channelId){
            case ChannelAccountConst::channel_Joom:
                $accountId = Cache::store('JoomShop')->getAccountId($accountId);
                break;
        }
        $model = (new BasicAccountService())->getServerModel($channelId);
        $base_account_id = $model->where('id',$accountId)->value('base_account_id');
        if(!$base_account_id){
            return false;
        }
        //2.同账号基础资料ID的其他平台账号ID
        $where = [
            'id' => ['<>',$accountId],
            'base_account_id' => $base_account_id,
        ];
        $otherBAI = $model->where($where)->column('id');
        return $otherBAI;
    }

    public function getServerModel($channel,$isShop = false)
    {
        $service = '';
        switch ($channel) {
            case ChannelAccountConst::channel_ebay:
                $service = new EbayAccount();
                break;
            case ChannelAccountConst::channel_amazon:
                $service = new AmazonAccount();
                break;
            case ChannelAccountConst::channel_wish:
                $service = new WishAccount();
                break;
            case ChannelAccountConst::channel_aliExpress:
                $service = new AliexpressAccount();
                break;
            case ChannelAccountConst::channel_CD:
                $service = new CdAccount();
                break;
            case ChannelAccountConst::channel_Lazada:
                $service = new LazadaAccount();
                break;
            case ChannelAccountConst::channel_Joom:
                $service = new JoomAccount();
                break;
            case ChannelAccountConst::channel_Pandao:
                $service = new PandaoAccount();
                break;
            case ChannelAccountConst::channel_Shopee:
                $service = new ShopeeAccount();
                break;
            case ChannelAccountConst::channel_Paytm:
                $service = new PaytmAccount();
                break;
            case ChannelAccountConst::channel_Walmart:
                $service = new WalmartAccount();
                break;
            case ChannelAccountConst::channel_Vova:
                $service = new VovaAccount();
                break;
            case ChannelAccountConst::Channel_Jumia:
                $service = new JumiaAccount();
                break;
            case ChannelAccountConst::Channel_umka:
                $service = new UmkaAccount();
                break;
            case ChannelAccountConst::channel_Newegg:
                $service = new NeweggAccount();
                break;
            case ChannelAccountConst::channel_Oberlo:
                $service = new OberloAccount();
                break;
            case ChannelAccountConst::channel_Shoppo:
                $service = new ShoppoAccount();
                break;
            case ChannelAccountConst::channel_Zoodmall:
                $service = new ZoodmallAccount();
                break;
            case ChannelAccountConst::channel_Yandex:
                $service = new YandexAccount();
                break;
            case ChannelAccountConst::channel_Daraz:
                $service = new DarazAccount();
                break;
            case ChannelAccountConst::channel_Fummart:
                $service = new FummartAccount();
                break;
        }
        return $service;
    }
}