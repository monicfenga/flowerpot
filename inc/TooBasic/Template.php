<?php

namespace TooBasic;

#[\AllowDynamicProperties]
class Template {
	/**
	 * 模板文件名（不含扩展名）
	 * 
	 * 用于指定要加载的模板文件。
	 * 
	 * @var string|null
	 */
	protected $_file;

	/**
	 * 创建一个新的模板实例。
	 * 
	 * @param string|null $file 模板文件名（不含扩展名），如果为 null 则创建一个空模板
	 */
	public function __construct($file = null) {
		$this->_file = $file;
	}

	/**
	 * 获取包装后的模板实例
	 * 
	 * 创建一个新的模板实例，用于包装当前模板。
	 * 包装后的模板实例包含当前模板的内容。
	 * 
	 * @return Template 包装后的模板实例
	 */
	public function getWrapped() {
		$wrapped = $this->get('_wrapper');
		$wrapped->content = $this;

		return $wrapped;
	}

	/**
	 * 获取子模板实例
	 * 
	 * 创建一个新的模板实例，用于包含当前模板的内容。
	 * 子模板实例的文件名是 $file。
	 * 
	 * @param string $file 子模板文件名（不含扩展名）
	 * @return Template 子模板实例
	 */
	public function get($file) {
		$tpl = clone $this;
		$tpl->_file = $file;

		return $tpl;
	}

	/**
	 * 显示模板
	 * 
	 * 创建一个新的模板实例，用于显示指定文件的模板。
	 * 模板实例的文件名是 $file。
	 * 变量数组 $variables 用于填充模板中的变量。
	 * 
	 * @param string $file 模板文件名（不含扩展名）
	 * @param array $variables 变量数组，用于填充模板中的变量
	 */
	public static function show($file, array $variables = array()) {
		$tpl = new self($file);

		foreach ($variables as $key => $value)
			$tpl->$key = $value;

		print $tpl->getWrapped();
	}

	/**
	 * 魔术方法：当调用不存在的方法时被调用
	 * 
	 * 抛出 404 异常，表示尝试调用了未知的方法。
	 * 这通常发生在请求的动作方法在控制器中不存在时。
	 * 
	 * @param string $name 被调用的方法名或属性名
	 * @param array $arguments 传递给方法的参数
	 * @return void
	 * @throws Exception 总是抛出 404 异常
	 */
	public function __call($name, $arguments) {
		if (isset($this->$name) && $this->$name instanceof \Closure)
		{
			return $this->$name(...$arguments);
		}

		throw new Exception('Call to undefined method Template::' . $name . '()');
	}

	/**
	 * 魔术方法：将模板实例转换为字符串
	 * 
	 * 调用此方法时，会自动加载模板文件并执行其中的代码。
	 * 返回模板渲染后的字符串。
	 * 
	 * @return string �染后的模板字符串
	 */
	public function __toString() {
		ob_start();

		require('tpl/' . $this->_file . '.php');

		return ob_get_clean();
	}
}
