<?php
namespace Oneshop\task;

use pocketmine\scheduler\AsyncTask;

class SpawnShopAsyncTask extends AsyncTask{
	public function __construct(){
	}

	public function onCompletion(\pocketmine\Server $server){
		$server->getPluginManager()->getPlugin("OneShop")->onAsyncRun();
	}

	public function onRun(){
	}
}