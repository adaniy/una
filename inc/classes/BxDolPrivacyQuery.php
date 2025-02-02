<?php defined('BX_DOL') or die('hack attempt');
/**
 * Copyright (c) UNA, Inc - https://una.io
 * MIT License - https://opensource.org/licenses/MIT
 *
 * @defgroup    UnaCore UNA Core
 * @{
 */

class BxDolPrivacyQuery extends BxDolDb
{
    protected $_aObject;

    protected $_sTable;
    protected $_sFieldId;
    protected $_sFieldOwnerId;

    protected $_sCachePrivacyObject;
    protected $_sCachePrivacyObjectDefault;

    protected $_sCacheGroup;
    protected $_sCacheGroupFriends;
    protected $_sCacheGroupsActive;

    protected $_sCacheTestedObject;

    public function __construct()
    {
        parent::__construct();
        $this->_aObject = array();

        $this->_sCachePrivacyObject = 'sys_privacy_object_';
        $this->_sCachePrivacyObjectDefault = 'sys_privacy_object_default_';

        $this->_sCacheGroup = 'sys_privacy_group_';
        $this->_sCacheGroupFriends = 'sys_privacy_group_friends_';
        $this->_sCacheGroupsActVis = 'sys_privacy_group_act_vis';

        $this->_sCacheTestedObject = 'sys_privacy_tested_object_';
    }

    static public function getPrivacyObject($sObject)
    {
        $oDb = BxDolDb::getInstance();

        $sQuery = $oDb->prepare("SELECT * FROM `sys_objects_privacy` WHERE `object` = ?", $sObject);

        $aObject = $oDb->getRow($sQuery);
        if(!$aObject || !is_array($aObject))
            return false;

        return $aObject;
    }

    public function init($aObject = array())
    {
        if(empty($aObject) || !is_array($aObject)) 
            return;

        $this->_aObject = $aObject;

        $this->_sTable = $this->_aObject['table'];
        $this->_sFieldId = $this->_aObject['table_field_id'];
        $this->_sFieldOwnerId = $this->_aObject['table_field_author'];
    }

    function getObjectInfo($sAction, $iObjectId)
    {
        if(empty($this->_sTable) || empty($this->_sFieldId) || empty($this->_sFieldOwnerId))
            return array();

        $sQuery = $this->prepare("SELECT `" . $this->_sFieldId . "` AS `id`, `" . $this->_sFieldOwnerId . "` AS `owner_id`, `" . $sAction . "` AS `group_id` FROM `" . $this->_sTable . "` WHERE `" . $this->_sFieldId . "`=? LIMIT 1", $iObjectId);

        $sCacheKey = $this->_sCacheTestedObject . $this->_sTable . '_' . $this->_aObject['action'] . '_' . $iObjectId;
        return $this->fromMemory($sCacheKey, 'getRow', $sQuery);
    }

    function getGroupsBy($aParams)
    {
        switch($aParams['type']) {
            case 'id':
                $sCacheFunction = 'fromCache';
                $sCacheName = $this->_sCacheGroup . $aParams['id'];
                $sMethod = 'getRow';
                $sWhereClause = $this->prepareAsString("`id`=?", (int)$aParams['id']);
                break;

            case 'active':
                $sCacheFunction = 'fromMemory';
                $sCacheName = $this->_sCacheGroupsActVis;
                $sMethod = 'getAll';
                $sWhereClause = "`active`='1' AND `visible`='1'";
                break;
        }

        $sSql = "SELECT
                   `id`,
                   `title`,
                   `check`,
                   `active`
                FROM `sys_privacy_groups`
                WHERE " . $sWhereClause;

        return !empty($sCacheFunction) && !empty($sCacheName) ? $this->$sCacheFunction($sCacheName, $sMethod, $sSql) : $this->$sMethod($sSql);
    }

    function getActions($iOwnerId)
    {
        $sQuery = $this->prepare("SELECT
                    `tm`.`uri` AS `module_uri`,
                    `tm`.`title` AS `module_title`,
                    `ta`.`id` AS `action_id`,
                    `ta`.`title` AS `action_title`,
                    `ta`.`default_group` AS `action_default_value`,
                    `td`.`group_id` AS `default_value`
                FROM `sys_objects_privacy` AS `ta`
                LEFT JOIN `sys_privacy_defaults` AS `td` ON `ta`.`id`=`td`.`action_id` AND `td`.`owner_id`=?
                INNER JOIN `sys_modules` AS `tm` ON `ta`.`module`=`tm`.`uri`
                WHERE 1
                ORDER BY `tm`.`title`", $iOwnerId);

        return $this->getAll($sQuery);
    }

    function getTitle($sModule, $sAction)
    {
        $aAction = $this->_getAction($sModule, $sAction);
        return !empty($aAction) && isset($aAction['title']) ? $aAction['title'] : '';
    }

    function getDefaultGroup($sModule, $sAction)
    {
        $aAction = $this->_getAction($sModule, $sAction);
        return !empty($aAction) && isset($aAction['default_group']) ? $aAction['default_group'] : BX_DOL_PG_DEFAULT;
    }

    function getDefaultGroupByUser($sModule, $sAction, $iOwnerId)
    {
        $sQuery = $this->prepare("SELECT
               `td`.`group_id`
            FROM `sys_objects_privacy` AS `ta`
            LEFT JOIN `sys_privacy_defaults` AS `td` ON `ta`.`id`=`td`.`action_id` AND `td`.`owner_id`=?
            WHERE
                `ta`.`module`=? AND `ta`.`action`=?
            LIMIT 1", $iOwnerId, $sModule, $sAction);

        $sCacheKey = $this->_sCachePrivacyObjectDefault . $sModule . '_' . $sAction . '_' . $iOwnerId;
        return $this->fromMemory($sCacheKey, 'getOne', $sQuery);
    }

    function replaceDefaulfGroup($iActionId, $iOwnerId, $iGroupId)
    {
        $sSql = $this->prepare("REPLACE INTO
                `sys_privacy_defaults`
            SET
                `owner_id`=?,
                `action_id`=?,
                `group_id`=?", $iOwnerId, $iActionId, $iGroupId);

        return $this->query($sSql);
    }

    public function getGroupCustom($aParams)
    {
        $CNF = &$this->_oConfig->CNF;

        $sDiv = ',';
    	$aMethod = array('name' => 'getAll', 'params' => array(0 => 'query'));

    	$sSelectClause = "`tg`.*";
    	$sJoinClause = $sWhereClause = $sGroupClause = $sOrderClause = $sLimitClause = "";
        switch($aParams['type']) {
            case 'id':
            	$aMethod['name'] = 'getRow';
            	$aMethod['params'][1] = array(
                    'id' => $aParams['id']
                );

                $sWhereClause = " AND `tg`.`id`=:id";
                break;

            case 'id_ext':
            	$aMethod['name'] = 'getRow';
            	$aMethod['params'][1] = array(
                    'id' => $aParams['id']
                );

                $sSelectClause .= ", GROUP_CONCAT(`tgm`.`member_id` SEPARATOR  '" . $sDiv . "') AS `members`";
                $sJoinClause = "LEFT JOIN `sys_privacy_groups_custom_members` AS `tgm` ON `tg`.`id`=`tgm`.`group_id`";
                $sWhereClause = " AND `tg`.`id`=:id";
                $sGroupClause = "`tg`.`id`";
                break;

            case 'pco':
                $aMethod['name'] = 'getRow';
            	$aMethod['params'][1] = array(
                    'profile_id' => $aParams['profile_id'],
                    'content_id' => $aParams['content_id'],
                    'object' => $aParams['object'],
                );

                $sWhereClause = " AND `tg`.`profile_id`=:profile_id AND `tg`.`content_id`=:content_id AND `tg`.`object`=:object";
                break;

            case 'pcog_ext':
                $aMethod['name'] = 'getRow';
            	$aMethod['params'][1] = array(
                    'profile_id' => $aParams['profile_id'],
                    'content_id' => $aParams['content_id'],
                    'object' => $aParams['object'],
                    'group_id' => $aParams['group_id'],
                );

                $sSelectClause .= ", GROUP_CONCAT(`tgm`.`member_id` SEPARATOR  '" . $sDiv . "') AS `members`";
                $sJoinClause = "LEFT JOIN `sys_privacy_groups_custom_members` AS `tgm` ON `tg`.`id`=`tgm`.`group_id`";
                $sWhereClause = " AND `tg`.`profile_id`=:profile_id AND (`tg`.`content_id`=:content_id" . (!empty($aParams['content_id']) ? " OR `tg`.`content_id`='0'" : "") . ") AND `tg`.`object`=:object AND `tg`.`group_id`=:group_id";
                $sGroupClause = "`tg`.`id`";
                break;

            case 'profile_id':
            	$aMethod['params'][1] = array(
                    'profile_id' => $aParams['profile_id']
                );

                $sWhereClause = " AND `tg`.`profile_id`=:profile_id";
                break;
        }

        $sGroupClause = !empty($sGroupClause) ? "GROUP BY " . $sGroupClause : $sGroupClause;
        $sOrderClause = !empty($sOrderClause) ? "ORDER BY " . $sOrderClause : $sOrderClause;
        $sLimitClause = !empty($sLimitClause) ? "LIMIT " . $sLimitClause : $sLimitClause;

        $aMethod['params'][0] = "SELECT
                " . $sSelectClause . "
            FROM `sys_privacy_groups_custom` AS `tg` " . $sJoinClause . " 
            WHERE 1" . $sWhereClause . " " . $sGroupClause . " " . $sOrderClause . " " . $sLimitClause;

        $aResult = call_user_func_array(array($this, $aMethod['name']), $aMethod['params']);
        if(in_array($aParams['type'], array('id_ext', 'pcog_ext')) && !empty($aResult) && is_array($aResult))
            $aResult['members'] = explode($sDiv, $aResult['members']);

        return $aResult;
    }

    public function insertGroupCustom($aParamsSet)
    {
        if(empty($aParamsSet))
            return false;

        if(!$this->query("INSERT INTO `sys_privacy_groups_custom` SET " . $this->arrayToSQL($aParamsSet)))
            return false;

        return (int)$this->lastId();
    }

    public function updateGroupCustom($aParamsSet, $aParamsWhere)
    {
        if(empty($aParamsSet) || empty($aParamsWhere))
            return false;

        return $this->query("UPDATE `sys_privacy_groups_custom` SET " . $this->arrayToSQL($aParamsSet) . " WHERE " . $this->arrayToSQL($aParamsWhere, " AND "));
    }

    public function deleteGroupCustom($aParamsWhere)
    {
        if(empty($aParamsWhere))
            return false;

        $sWhereClause = $this->arrayToSQL($aParamsWhere, " AND ");

        $aGroup = $this->getRow("SELECT * FROM `sys_privacy_groups_custom` WHERE " . $sWhereClause . " LIMIT 1");
        if(empty($aGroup) || !is_array($aGroup))
            return true;

        $bResult = $this->query("DELETE FROM `sys_privacy_groups_custom` WHERE " . $sWhereClause . " LIMIT 1") !== false;
        if($bResult)
            $this->deleteGroupCustomMember(array('group_id' => $aGroup['id']));

        return $bResult;
    }

    public function insertGroupCustomMember($aParamsSet)
    {
        if(empty($aParamsSet))
            return false;

        return $this->query("INSERT INTO `sys_privacy_groups_custom_members` SET " . $this->arrayToSQL($aParamsSet));
    }

    public function deleteGroupCustomMember($aParamsWhere)
    {
        if(empty($aParamsWhere))
            return false;

        return $this->query("DELETE FROM `sys_privacy_groups_custom_members` WHERE " . $this->arrayToSQL($aParamsWhere, " AND "));
    }

    function getContentByGroupAsSQLPart($sField, $mixedGroupId)
    {
        if(is_array($mixedGroupId))
            $sWhere = " AND `" . $this->_sTable . "`.`" . $sField . "` IN (" . $this->implode_escape($mixedGroupId) . ")";
        else
            $sWhere = $this->prepareAsString(" AND `" . $this->_sTable . "`.`" . $sField . "` = ?", $mixedGroupId);

        return array(
            'where' => $sWhere
        );
    }

    protected function _getAction($sModule, $sAction)
    {
        $sQuery = $this->prepare("SELECT
                `id`,
                `module`,
                `action`,
                `title`,
                `default_group`
            FROM `sys_objects_privacy` AS `ta`
            WHERE `module`=? AND `action`=?
            LIMIT 1", $sModule, $sAction);

        $sCacheKey = $this->_sCachePrivacyObject . $sModule . '_' . $sAction;
        return $this->fromCache($sCacheKey, 'getRow', $sQuery);
    }
}

/** @} */
