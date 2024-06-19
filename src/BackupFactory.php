<?php

declare(strict_types=1);

namespace php\databackup;

use php\databackup\mysql\Backup;
use PDO;

class BackupFactory
{
    private static $instance = null;

    /**
     * 单例方法,用于获取数据库备份实例
     * 根据传入的数据库连接参数,创建或返回一个已存在的备份实例
     * 这种设计模式确保了在整个应用程序中,对于相同的数据库连接参数
     * 只会存在一个单一的备份实例,从而优化资源使用和提高效率
     *
     * @param string $scheme 数据库连接协议,目前只支持'mysql'
     * @param string $server 数据库服务器地址
     * @param string $dbname 数据库名称
     * @param string $username 登录数据库的用户名
     * @param string $password 登录数据库的密码
     * @param string $code 数据库字符集,默认为'utf8'
     * @return Backup 返回数据库备份实例
     */
    public static function instance($scheme, $server, $dbname, $username, $password, $code = 'utf8')
    {
        // 使用函数参数的组合生成一个唯一标识,用于区分不同的数据库连接实例
        $args = md5(implode('_', func_get_args()));

        // 检查是否已经存在该标识的实例,如果不存在或为null,则需要创建新实例
        if (!isset(self::$instance[$args]) || self::$instance[$args] == null) {
            switch ($scheme) {
                case 'mysql':
                    // 创建PDO实例,用于连接到MySQL数据库
                    // 设置PDO属性以初始化命令,用于设置字符集
                    $pdo =  new PDO($scheme . ':host=' . $server . ';dbname=' . $dbname, $username, $password, [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES'" . $code . "';"]);
                    // 创建备份实例,并使用前面创建的PDO实例作为参数
                    self::$instance[$args] = new Backup($pdo);
                    break;
            }
        }
        // 返回对应的数据库备份实例
        return self::$instance[$args];
    }
}
