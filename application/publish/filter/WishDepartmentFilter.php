<?php
/**
 * Created by PhpStorm.
 * User: joy
 * Date: 17-11-25
 * Time: 下午1:46
 */

namespace app\publish\filter;

use app\common\service\Common;
use app\index\service\MemberShipService;
use app\common\filter\BaseFilter;
use app\common\model\Department;
use app\index\service\Department as DepartmentService;

class WishDepartmentFilter extends BaseFilter
{
    protected $scope = 'Department';

    public static function getName(): string
    {
        return 'wish-listing部门权限过滤器';
    }

    public static function config(): array
    {
        $model = new Department();
        $options = $model->field('id as value, name as label')->select();
        if($options)
        {
            foreach ($options as &$option)
            {
                $option['label']=(new DepartmentService)->getDepartmentNames($option['value']);
            }
        }

        return [
            'key' => 'type',
            'type' => static::TYPE_SELECT,
            'options' => $options
        ];
    }

    public function generate()
    {
        $type = $this->getConfig();
        return $type;exit();
        //查询账号
        $userInfo = Common::getUserInfo();
        $memberShipService = new MemberShipService();
        $accountList = $memberShipService->getAccountIDByUserId($userInfo['user_id']);
        $accountId = array_merge($type,$accountList);
        return $accountId;
    }
}