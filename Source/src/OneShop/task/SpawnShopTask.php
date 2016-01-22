<?php
namespace OneShop\task;

use pocketmine\scheduler\PluginTask;

class SpawnShopTask extends PluginTask{
	protected $owner;

 	public function onRun($currentTick){
		$this->owner->getServer()->getScheduler()->scheduleAsyncTask(new SpawnShopAsyncTask());
	}
}