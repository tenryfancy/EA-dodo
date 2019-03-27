<?php
/**
 * Created by PhpStorm.
 * User: joy
 * Date: 18-4-24
 * Time: 上午10:58
 */

namespace app\index\controller;

use app\common\cache\Cache;
use app\common\controller\Base;
use app\common\exception\JsonErrorException;
use app\common\service\Common;
use app\common\model\jumia\JumiaAccount as JumiaAccountModel;
use app\index\service\JumiaAccountService;
use think\Db;
use think\Exception;
use think\Request;

/**
 * @module 账号管理
 * @title jumia账号
 * @url /jumia-account
 * @package app\publish\controller
 * @author libiamin
 */
class JumiaAccount extends Base
{
    protected $jumiaAccountService;

    public function __construct()
    {
        parent::__construct();
        if(is_null($this->jumiaAccountService)){
            $this->jumiaAccountService = new JumiaAccountService();
        }
    }
    
    /**
     * @title jumia账号列表
     * @method GET
     * @param Request $request
     * @return \think\response\Json
     * @throws \Exception
     */
    public function index(Request $request)
    {
        try{
            $params = $request->param();
            $page = $request->get('page', 1);
            $pageSize = $request->get('pageSize', 20);
            $response = $this->jumiaAccountService->accountList($params,$page,$pageSize);
            return json($response);
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }

    /**
     * @title 添加账号
     * @method POST
     * @url /jumia-account
     * @param Request $request
     * @return \think\response\Json
     */
    public function add(Request $request)
    {
        try{
            $params = $request->param();
            $user = Common::getUserInfo($request);
            $uid = $user['user_id'];

            $response = $this->jumiaAccountService->add($params,$uid);
            if($response === false) {
                return json(['message' => $this->jumiaAccountService->getError()], 400);
            }
            return json(['message' => '添加成功','data' => $response]);
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }

    /**
     * @title 更新账号
     * @method PUT
     * @url /jumia-account
     * @return \think\Response
     */
    public function update(Request $request)
    {
        try{
            $params = $request->param();
            if(!$params['id']) {
                return json(['message' => '缺少必要参数ID'], 400);
            }
            $user = Common::getUserInfo($request);
            $uid = $user['user_id'];
            $response = $this->jumiaAccountService->add($params,$uid);
            if($response === false) {
                return json(['message' => $this->jumiaAccountService->getError()], 400);
            }
            return json(['message' => '更新成功','data' => $response]);
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }

    /**
     * @title 保存授权信息
     * @method put
     * @param Request $request
     * @url /jumia-account/save-token
     * @return array
     */
    public function saveToken(Request $request)
    {
        try {
            $params = $request->param();
            if(!$params['id']) {
                return json(['message' => '缺少必要参数ID'], 400);
            }
            $id = intval($params['id']);
            if (!$params['client_id']) {
                return json(['message' => '缺少必要参数client_id']);
            }
            if (!$params['client_secret']) {
                return json(['message' => '缺少必要参数client_secret']);
            }
            $user = Common::getUserInfo($request);
            $uid = $user['user_id'];
            $save_data['id'] = $id;
            $save_data['update_time'] = time();
            $save_data['client_secret'] = $params['client_secret'] ?? '';
            $save_data['client_id'] = $params['client_id'] ?? '';
            if($save_data['client_secret'] && $save_data['client_id']){
                $save_data['jumia_enabled'] = 1;
            }else{
                $save_data['jumia_enabled'] = 0;
            }
            $cache = Cache::store('JumiaAccount');
            foreach ($save_data as $key => $val) {
                $cache->updateTableRecord($id, $key, $val);
            }
            $model = new JumiaAccountModel();
            $model->add($save_data);
            $service = new JumiaAccountService();
            return $service->getOne($id);
        } catch (Exception $ex) {
            throw new JsonErrorException("File:{$ex->getFile()};Line:{$ex->getLine()};Message:{$ex->getMessage()}");
        }
    }

    /**
     * @title 查看账号
     * @method GET
     * @param  int $id
     * @url /jumia-account/:id
     * @return \think\response\Json
     */
    public function read($id)
    {
        $response = $this->jumiaAccountService->getOne($id);
        return json($response);
    }


    /**
     * @title 停用，启用账号
     * @method post
     * @url /jumia-account/states
     */
    public function changeStatus(Request $request)
    {
        $params = $request->param();
        if(!$params['id']) {
            return json(['message' => '缺少必要参数ID'], 400);
        }
        try{
            $response = $this->jumiaAccountService->changeStatus($params);
            if($response === false) {
                return json(['message' => $this->jumiaAccountService->getError()], 400);
            }
            return json(['message' => '操作成功','data' => $response]);
        }catch (Exception $exp){
            throw new JsonErrorException("File:{$exp->getFile()};Line:{$exp->getLine()};Message:{$exp->getMessage()}");
        }
    }

    /**
     * @title 批量开启
     * @url batch-set
     * @method post
     * @param Request $request
     * @return \think\response\Json
     * @throws Exception
     */
    public function batchSet(Request $request)
    {
        $params = $request->post();
        $result = $this->validate($params,[
            'ids|帐号ID' => 'require|min:1',
            'is_invalid|系统状态' => 'require|number',
            'sync_delivery|同步发货状态到jumia功能' => 'require|number',
            'download_order|抓取jumia订单功能' => 'require|number',
            'download_listing|抓取Listing功能' => 'require|number',
        ]);

        if (empty($result)) {
            throw new Exception($result);
        }

        //实例化模型
        $model = new JumiaAccountModel();

        if (isset($params['is_invalid']) && $params['is_invalid'] != '') {
            $data['is_invalid'] = (int)$params['is_invalid'];   //1 启用， 0未启用
        }
        if (isset($params['sync_delivery']) && $params['sync_delivery'] != '') {
            $data['sync_delivery'] = (int)$params['sync_delivery'];
        }
        if (isset($params['download_order']) && $params['download_order'] != '') {
            $data['download_order'] = (int)$params['download_order'];
        }
        if (isset($params['download_listing']) && $params['download_listing'] != '') {
            $data['download_listing'] = (int)$params['download_listing'];
        }

        $idArr = array_merge(array_filter(array_unique(explode(',',$params['ids']))));
        //开启事务
        Db::startTrans();
        try {
            if (empty($data)) {
                return json(['message' => '数据参数不能为空'], 200);
            }

            $data['update_time'] = time();
            $model->allowField(true)->update($data,['id' => ['in', $idArr]]);
            Db::commit();

            //更新缓存
            $cache = Cache::store('JumiaAccount');
            foreach ($idArr as $id) {
                foreach ($data as $k => $v) {
                    $cache->updateTableRecord($id, $k, $v);
                }
            }
            return json(['message' => '更新成功'], 200);
        } catch (Exception $ex) {
            Db::rollback();
            return json(['message' => '更新失败'], 400);
        }
    }

}