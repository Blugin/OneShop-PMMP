<?php
namespace OneShop;

class Task extends \pocketmine\scheduler\PluginTask{
	private $callable, $args;

	public function __construct(OneShop $plugin, callable $callable, array $args = []){
		$this->plugin = $plugin;
		parent::__construct($plugin);
		$this->callable = $callable;
		$this->args = $args;
	}

	public function onRun($currentTick){
		call_user_func_array($this->callable, $this->args);
	}
}