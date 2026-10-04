<?php namespace TooBasic;

#[\AllowDynamicProperties]
class Controller
{
	/**
	 * 分发 HTTP 请求到相应的控制器方法
	 * 
	 * 解析 URL 路径，提取请求方法和动作，然后实例化控制器来处理请求。
	 * 如果未提供路径，则使用 $_SERVER['REQUEST_URI']。
	 * 会自动移除查询字符串部分。
	 * 
	 * @param string|null $path 请求路径，如果为 null 则使用 $_SERVER['REQUEST_URI']
	 * @return void
	 */
	public static function dispatch(?string $path = null)
	{
		if (!isset($path))
			$path = $_SERVER['REQUEST_URI'];

		if (false !== strpos($path, '?'))
			$path = strstr($path, '?', true);

		$params = explode('/', substr($path, 1));
		$params = array_map('rawurldecode', $params);
		$action = array_shift($params);

		if ('' === $action)
			$action = 'index';

		new static(strtolower($_SERVER['REQUEST_METHOD']), $action, $params);
	}

	/**
	 * 控制器构造函数（私有，防止外部实例化）
	 * 
	 * 根据 HTTP 方法和动作调用相应的处理方法。
	 * HEAD 请求会被转换为 GET 请求。
	 * 首先调用 _construct() 进行初始化，然后尝试调用具体的方法（如 getIndex、postForm 等）。
	 * 如果方法不存在，则尝试调用通用的 get() 或 post() 方法。
	 * 所有异常都会被 _handle() 方法捕获和处理。
	 * 
	 * @param string $method HTTP 请求方法（get、post、put、delete 等）
	 * @param string $action 请求的动作/路径
	 * @param array $params 请求参数列表
	 * @return mixed 返回处理方法的结果
	 * @throws \Exception 当方法不存在时抛出异常
	 */
	final private function __construct(string $method, string $action, array $params)
	{
		if ('head' === $method)
			$method = 'get';

		try
		{
			$this->_construct($method, $action, $params);

			if (method_exists($this, $method . ucfirst($action)))
				return $this->{$method . ucfirst($action)}(...$params);

			$this->$method(...array_merge(array($action), $params));
		}
		catch (\Exception $e)
		{
			$this->_handle($e);
		}
	}

	/**
	 * 初始化钩子方法
	 * 
	 * 在具体动作方法执行之前调用，用于初始化控制器状态。
	 * 子类可以重写此方法以执行自定义初始化逻辑。
	 * 
	 * @param string $method HTTP 请求方法
	 * @param string $action 请求的动作
	 * @param array $params 请求参数
	 * @return void
	 */
	protected function _construct(string $method, string $action, array $params)
	{
	}

	/**
	 * 魔术方法：当调用不存在的方法时被调用
	 * 
	 * 抛出 404 异常，表示尝试调用了未知的方法。
	 * 这通常发生在请求的动作方法在控制器中不存在时。
	 * 
	 * @param string $method 被调用的方法名
	 * @param array $arguments 传递给方法的参数
	 * @return void
	 * @throws Exception 总是抛出 404 异常
	 */
	public function __call(string $method, array $arguments)
	{
		throw new Exception('404 - Unknown method: '. $method);
	}

	/**
	 * 异常处理方法
	 * 
	 * 当请求处理过程中发生异常时被调用。
	 * 设置适当的 HTTP 响应码并输出异常信息。
	 * 子类可以重写此方法以自定义错误处理逻辑。
	 * 
	 * @param \Exception $e 捕获的异常对象
	 * @return void
	 */
	protected function _handle(\Exception $e)
	{
		if (!headers_sent())
			http_response_code($e instanceof Exception ? $e->getCode() : 500);

		print $e;
	}
}
