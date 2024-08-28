<?php

declare(strict_types=1);

namespace php\databackup;

interface IRecovery
{
    /**
     * 设置待恢复的SQL文件目录
     * 
     * 本函数用于设定数据库恢复操作时所需SQL文件所在的目录路径
     * 通过指定这个目录,系统可以从中找到所有的SQL文件来进行数据库恢复操作
     * 
     * @param string $dir SQL文件所在的目录路径
     * @return void
     */
    public function setSqlfiledir($dir);

    /**
     * 恢复功能的方法
     * 
     * 本方法旨在提供一种机制,用于恢复对象到先前的状态
     * 这可能是为了撤销之前的某些操作,或者是在错误发生后恢复到一个已知的良好状态
     * 
     * @return void 本方法没有返回值,它的主要目的是通过操作对象状态来恢复到预期的状态
     */
    public function recovery();

    /**
     * AJAX恢复功能
     * 
     * 该方法旨在处理通过AJAX请求发起的数据恢复操作
     * 可能是恢复删除的数据、恢复备份的数据等
     * 具体实现细节依赖于应用程序的具体需求
     * 由于方法体未提供具体实现代码,因此这里仅对方法的目的和使用场景进行说明
     * 
     * @return void 该方法可能不返回任何值,也可能返回特定的响应数据,具体取决于AJAX请求的处理逻辑
     */
    public function ajaxrecovery();
}
