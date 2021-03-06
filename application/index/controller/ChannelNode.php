<?php

namespace app\index\controller;

use app\common\controller\Base;
use app\index\service\ChannelNodeService;
use app\common\service\Common as CommonService;
use think\Request;

/**
 * @module 基础设置
 * @title 平台自动登录
 * @author libaimin
 * @url channel-node
 * Created by PhpStorm.
 * User: libaimin
 * Date: 2018/10/22
 * Time: 14:46
 */
class ChannelNode extends Base
{
    protected $channelNodeServer;

    protected function init()
    {
        if (is_null($this->channelNodeServer)) {
            $this->channelNodeServer = new ChannelNodeService();
        }
    }

    /**
     * @title 平台自动登录列表
     * @param Request $request
     * @return \think\response\Json
     */
    public function index(Request $request)
    {
        $result = $this->channelNodeServer->lists($request);
        return json($result, 200);
    }

    /**
     * @title 获取平台自动登录信息
     * @param $id
     * @return \think\response\Json
     */
    public function edit($id)
    {
        $result = $this->channelNodeServer->read($id);
        return json($result, 200);
    }

    /**
     * @title 保存平台自动登录信息
     * @param Request $request
     * @return \think\response\Json
     */
    public function save(Request $request)
    {
        $data['channel_id'] = trim($request->post('channel_id', ''));
        $data['channel_site'] = trim($request->post('channel_site', ''));
        $data['website_url'] = trim($request->post('website_url', ''));
        $data['node_info'] = $request->post('node_info', '');
        $data['verification_website_url']=trim($request->post('verification_website_url'));//验证网站信息
        $data['verification_node_info']=trim($request->post('verification_node_info'));//验证节点信息
        $data['relation_module']=trim($request->post('relation_module'));
        $data['type']=$request->post('type','');
        $validateChannelNodeServer =  validate('ChannelNode');
        if(!$validateChannelNodeServer->check($data)){
            return json(['message' => $validateChannelNodeServer->getError()],400);
        }
        $id = $this->channelNodeServer->save($data);
        return json(['message' => '新增成功', 'data' => $id], 200);
    }

    /**
     * @title 更新平台自动登录信息
     * @param Request $request
     * @param $id
     * @return \think\response\Json
     */
    public function update(Request $request, $id)
    {
        $data['channel_id'] = trim($request->put('channel_id', ''));
        $data['channel_site'] = trim($request->put('channel_site', ''));
        $data['website_url'] = trim($request->put('website_url', ''));
        $data['node_info'] = $request->put('node_info', '');
        $data['type'] = strval($request->put('type', ''));
        $data['relation_module']=trim($request->put('relation_module'));//收款平台
        $data['verification_website_url']=trim($request->put('verification_website_url'));//验证网站信息
        $data['verification_node_info']=trim($request->put('verification_node_info'));//验证节点信息
        $data['id'] = $id;
        unset($data['id']);
        $dataInfo = $this->channelNodeServer->update($id, $data);
        return json(['message' => '修改成功', 'data' => $dataInfo], 200);
    }

    /**
     * @title 删除
     * @url /channel-node/:id
     * @method delete
     */
    public function delete($id)
    {
        if (empty($id)) {
            return json(['message' => '请求参数错误'], 400);
        }
        $dataInfo = $this->channelNodeServer->delete($id);
        return json(['message' => '删除成功', 'data' => $dataInfo], 200);
    }

    /**
     * @title 节点类型
     * @url /channel-node/node-type
     * @method get
     */
    public function nodeTpye()
    {
        $dataInfo = $this->channelNodeServer->nodeTpye();
        return json(['message' => '请求成功', 'data' => $dataInfo], 200);
    }

}