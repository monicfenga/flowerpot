<?php namespace TooBasic;

#[\AllowDynamicProperties]
class Exception extends \Exception
{
	/**
	 * 异常构造函数
	 * 
	 * 支持使用 sprintf 格式的参数来格式化异常消息。
	 * 允许传递参数数组来动态构建异常消息。
	 * 
	 * @param string $message 异常消息，可以是 sprintf 格式字符串
	 * @param array $params 用于格式化消息的参数数组
	 * @param int $code HTTP 错误码，默认为 500
	 * @param Exception|null $cause 引起此异常的原因（前一个异常）
	 */
	public function __construct(string $message, array $params = [], int $code = 500, Exception $cause = null)
	{
		if (!empty($params))
			$message = vsprintf($message, $params);

		parent::__construct($message, $code, $cause);
	}

	/**
	 * 错误处理函数
	 * 
	 * 作为 PHP 错误处理器的回调函数。
	 * 当错误报告启用时记录错误信息，并抛出异常。
	 * 
	 * @param int $number 错误级别代码
	 * @param string $string 错误消息
	 * @param string $file 发生错误的文件名
	 * @param int $line 发生错误的行号
	 * @return void
	 * @throws Exception 总是抛出异常
	 */
	public static function errorHandler($number, $string, $file, $line): void
	{
		if (ini_get('error_reporting') > 0)
			error_log($string .' in '. $file .' on line '. $line);

		throw new Exception('An unexpected error has occurred');
	}
}