<?php
namespace app\common\validate;

use \think\Validate;

/**
 * Created by PhpStorm.
 * User: PHILL
 * Date: 2016/10/28
 * Time: 9:57
 */
class OrderVirtualRuleSet extends Validate
{
    protected $rule = [
        ['title', 'require|unique:VirtualRuleSet,title', '名称不能为空！|名称已存在！'],
    ];
}