<?php

namespace OneShop;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\TranslationContainer as Translation;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\server\ServerCommandEvent;
use pocketmine\event\server\RemoteServerCommandEvent;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\network\protocol\AddItemEntityPacket;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\network\protocol\RemoveEntityPacket;
use pocketmine\network\protocol\SetEntityLinkPacket;
use pocketmine\network\protocol\SetEntityDataPacket;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\level\sound\ClickSound;
use pocketmine\utils\TextFormat as Color;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\block\Block;
use pocketmine\math\Vector3;
use pocketmine\Player;
use OneShop\task\SpawnShopTask;

class OneShop extends PluginBase implements Listener{
	const TOUCH_MODE = 0;
	const TOUCH_POS = 1;
	const INFO_TIME = 2;
	const ITEM_ID = 1;
	const BUY_AMOUNT = 2;
	const BUY_PRICE = 3;
	const SELL_AMOUNT = 4;
	const SELL_PRICE = 5;
	const LEVEL = 6;
	const MODE_BUY = 0;
	const MODE_SELL = 1;
	const MODE_ADD = 2;
	const MODE_REMOVE = 3;
	const MODE_REMOVEMODE = 4;
	const MODE_BUILD = 5;
	const PACKET_ADD_ITEM_ENTITY = 0;
	const PACKET_ADD_ENTITY = 1;
	const PACKET_SET_ENTITY_LINK = 2;
	const PACKET_REMOVE_ENTITY_FIRST = 3;
	const PACKET_REMOVE_ENTITY_SECOND = 4;
	const PACKET_SET_ENTITY_DATA_SHOW = 5;
	const PACKET_SET_ENTITY_DATA_HIDE = 6;

	const REGEX_ID = "/([\?]|[a-zA-Z_]+|[0-9]+)[\:]([\?]|[0-9]+)|([\?]|[a-zA-Z_]+|[0-9]+)/";
	const REGEX_AMOUNT = "/([\?])|([1-9][0-9]*)[\~|\-|\:]([0-9][0-9]*)|([0-9]+)|([\-])/";

 	private $data = [], $spawned = [], $eids = [], $editTouch = [], $shopTouch = [], $placed = [], $packets = [];
	private $addItemEntityPk, $addEntityPk, $removeEntityPk, $setEntityLinkPk, $setEntityDataPk;

	public function onLoad(){
		$this->addItemEntityPk = new AddItemEntityPacket();
		$this->addItemEntityPk->x = 0;
		$this->addItemEntityPk->y = 0;
		$this->addItemEntityPk->z = 0;
		$this->addItemEntityPk->speedX = 0;
		$this->addItemEntityPk->speedY = 0;
		$this->addItemEntityPk->speedZ = 0;
		$this->addEntityPk = new AddEntityPacket();
		$this->addEntityPk->type = 69;
		$this->addEntityPk->speedX = 0;
		$this->addEntityPk->speedY = 0;
		$this->addEntityPk->speedZ = 0;
		$this->addEntityPk->yaw = 0;
		$this->addEntityPk->pitch = 0;
		$this->addEntityPk->metadata = [
			Entity::DATA_SHOW_NAMETAG => [Entity::DATA_TYPE_BYTE, false], 			Entity::DATA_SILENT => [Entity::DATA_TYPE_BYTE, true],
			Entity::DATA_NO_AI => [Entity::DATA_TYPE_BYTE, true]
		];
		$this->removeEntityPk = new RemoveEntityPacket();
		$this->setEntityLinkPk = new SetEntityLinkPacket();
		$this->setEntityLinkPk->type = 1;
		$this->setEntityDataPk = new SetEntityDataPacket();
 	}

	public function onEnable(){
		$this->getServer()->getLogger()->info(Color::GREEN . "Find economy plugin...");
		$pluginManager = $this->getServer()->getPluginManager();
		if(!($this->money = $pluginManager->getPlugin("PocketMoney")) && !($this->money = $pluginManager->getPlugin("EconomyAPI")) && !($this->money = $pluginManager->getPlugin("MassiveEconomy")) && !($this->money = $pluginManager->getPlugin("Money"))){
			$this->getLogger()->info(Color::RED . "[OneShop] " . ($this->isKorean() ? "경제 플러그인을 찾지 못했습니다." : "Failed find economy plugin..."));
			$this->setEnabled(false);
		}elseif(!($this->farm = $pluginManager->getPlugin("FarmAPI"))){
			$this->getLogger()->info(Color::RED . "[OneShop] " . ($this->isKorean() ? "농장 플러그인을 찾지 못했습니다." : "Failed find farm plugin..."));
			$this->setEnabled(false);
		}else{
			$this->getLogger()->info(Color::GREEN . "[OneShop] " . ($this->isKorean() ? "경제 플러그인을 찾았습니다. : " : "Finded economy plugin : ") . $this->money->getName());
			$this->getLogger()->info(Color::GREEN . "[OneShop] " . ($this->isKorean() ? "농장 플러그인을 찾았습니다. : " : "Finded farm plugin "));
			$this->loadData();
			$pluginManager->registerEvents($this, $this);
			$this->getServer()->getScheduler()->scheduleRepeatingTask(new SpawnShopTask($this), 5);
		}
	}

	public function onDisable(){
		$this->saveData();
	}

	public function onCommand(CommandSender $sender, Command $cmd, $label, array $sub){
		$ik = $this->isKorean();
		if(!isset($sub[0]) || $sub[0] == ""){
			if(isset($this->editTouch[$name = $sender->getName()])){
				unset($this->editTouch[$name]);
				$sender->sendMessage(Color::YELLOW . "[OneShop] " . ($ik ? "상점 편집모드가 해제됩니다." : "Shop edit mode is disabled"));
				return true;
			}else{
				return false;
			}
		}
		switch(strtolower($sub[0])){
			case "add":
			case "a":
			case "추가":
				if(!$sender->hasPermission("oneshop.cmd.add")){
					$r = new Translation(Color::RED . "%commands.generic.permission");
				}elseif(isset($this->editTouch[$name = $sender->getName()]) && $this->editTouch[$name][self::TOUCH_MODE] == self::MODE_ADD){
					unset($this->editTouch[$name]);
					$r = Color::YELLOW . "[OneShop] " . ($ik ? "상점 추가모드가 해제됩니다." : "Shop add mode is disabled");
				}elseif(!isset($sub[6]) || $sub[1] == "" || $sub[2] == "" || $sub[3] == "" || $sub[4] == "" || $sub[5] == "" || $sub[6] == ""){
					$r = Color::RED .  "Usage: /OneShop Add(A) " . ($ik ? "<아이템ID> <구매갯수> <구매가격> <판매갯수> <판매가격> <농장레벨>" : "<ItemID> <BuyAmount> <BuyPrice> <SellAmount> <SellPrice> <FarmLevel>") . " [X] [Y] [Z] [World]";
				}else{
					if(!preg_match(self::REGEX_ID, $sub[1], $idMatch)){
						$r = Color::RED . "[OneShop] $sub[1]" . ($ik ? "은(는) 잘못된 아이템ID입니다." : " is invalid item id");
					}elseif(!preg_match(self::REGEX_AMOUNT, $sub[2], $buyAmountMatch)){
						$r = Color::RED . "[OneShop] $sub[2]" . ($ik ? "은(는) 잘못된 갯수입니다." : " is invalid count");
					}elseif(!is_numeric($sub[3]) || floor($sub[3]) < 0){
						$r = Color::RED . "[OneShop] $sub[3]" . ($ik ? "은(는) 잘못된 가격입니다." : " is invalid price");
					}elseif(!preg_match(self::REGEX_AMOUNT, $sub[4], $sellAmountMatch)){
						$r = Color::RED . "[OneShop] $sub[4]" . ($ik ? "은(는) 잘못된 갯수입니다." : " is invalid count");
					}elseif(!is_numeric($sub[5]) || floor($sub[5]) < 0){
						$r = Color::RED . "[OneShop] $sub[5]" . ($ik ? "은(는) 잘못된 가격입니다." : " is invalid price");
					}elseif(!is_numeric($sub[6]) || floor($sub[6]) < 1){
						$r = Color::RED . "[OneShop] $sub[6]" . ($ik ? "은(는) 잘못된 레벨입니다." : " is invalid level");
					}else{
						if(!empty($buyAmountMatch[2])){
							$sub[2] = $buyAmountMatch[2] . "~" . $buyAmountMatch[3];
						}elseif(is_numeric($sub[2])){
							$sub[2] = floor($sub[2]);
						}
						if(!empty($sellAmountMatch[2])){
							$sub[4] = $sellAmountMatch[2] . "~" . $sellAmountMatch[3];
						}elseif(is_numeric($sub[4])){
							$sub[4] = floor($sub[4]);
						}
 						$item = Item::fromString($sub[1] = !empty($idMatch[1]) ?
 							($idMatch[1] == "?" ? ($sender instanceof Player ? $sender->getInventory()->getItemInHand()->getID() . ":" . ($idMatch[2] == "?" ? "?" : $sender->getInventory()->getItemInHand()->getDamage()) : 0) : $idMatch[1] . ":" . $idMatch[2])
 							: 	($idMatch[3] == "?" ? ($sender instanceof Player ? $sender->getInventory()->getItemInHand()->getID() . ":" . $sender->getInventory()->getItemInHand()->getDamage() : 0) : $idMatch[3])
 						);
						if($item->getID() == 0){
							$r = Color::RED . "[OneShop] $sub[1]" . ($ik ? "은(는) 잘못된 아이템ID입니다." : " is invalid item id");
						}else{
							$id = $item->getID() . ":" . (!empty($idMatch[2]) ? ($idMatch[2] == "?" ? "?" : $item->getDamage()) : $item->getDamage());
							if(!($isPlayer = $sender instanceof Player) || $isPlayer && isset($sub[9]) && $sub[7] != "" | $sub[8] != "" | $sub[9] != ""){
								if(!isset($sub[9]) || $sub[7] == "" | $sub[8] == "" | $sub[9] == ""){
									$r = Color::RED .  "Usage: /OneShop Add(A) " . ($ik ? "<아이템ID> <판매갯수> <판매가격> <구매갯수> <구매가격> <농장레벨>" : "<ItemID> <BuyAmount> <BuyPrice> <SellAmount> <SellPrice> <FarmLevel>") . " [X] [Y] [Z] [World]";
								}elseif(!is_numeric($sub[7])){
									$r = Color::RED . "[OneShop] $sub[7]" . ($ik ? "은(는) 잘못된 X좌표입니다." : " is invalid X coordinate");
								}elseif(!is_numeric($sub[8]) || ($sub[8] = floor($sub[8])) < 0 || ($sub[8] = floor($sub[8])) > 127){
									$r = Color::RED . "[OneShop] $sub[8]" . ($ik ? "은(는) 잘못된 Y좌표입니다." : " is invalid Y coordinate");
								}elseif(!is_numeric($sub[9])){
									$r = Color::RED . "[OneShop] $sub[9]" . ($ik ? "은(는) 잘못된 Z좌표입니다." : " is invalid Z coordinate");
								}else{
									if(isset($sub[10]) && ($level = $this->getServer()->getLevelByName($sub[10])) === null){
										$r = Color::RED . "[OneShop] $sub[10]" . ($ik ? "은(는) 잘못된 월드명입니다." : " is invalid world name");
									}elseif(!isset($sub[10])){
										$level = $isPlayer ? $sender->getLevel() : $this->getServer()->getDefaultLevel();
									}
									if(!isset($r)){
										if(!$level->isChunkLoaded($chunkX = ($sub[7] = floor($sub[7])) >> 4, $chunkZ = ($sub[9] = floor($sub[9])) >> 4) || !$level->isChunkGenerated($chunkX, $chunkZ)){
											$r = Color::RED . "[OneShop] $sub[7],$sub[8],$sub[9]" . ($ik ? "은(는) 잘못된 좌표입니다." : " is invalid coordinate");
										}else{
											if(isset($this->data[$pos = "$sub[7]:$sub[8]:$sub[9]:" . $level->getFolderName()])){
												$r = Color::RED . "[OneShop] " . ($ik ? "그곳에는 이미 상점이 있습니다." : "Shop is already exist in there");
											}else{
												$this->addShop($pos, $mode, $id, $sub[2], $sub[3], $sub[4], $sub[5], $sub[6]);
												$r = Color::YELLOW . "[OneShop] " . ($ik ? "상점을 추가했습니다.\n" . Color::GOLD . "  [OneShop][$pos] 아이디: " . $this->data[$pos][self::ITEM_ID] . ", 구매갯수: "  . (is_numeric($this->data[$pos][self::BUY_AMOUNT]) ? $this->data[$pos][self::BUY_AMOUNT] . ", " : ($this->data[$pos][self::BUY_AMOUNT] == "?" ? "모두, " : $this->data[$pos][self::BUY_AMOUNT]) . ", 개당") . " 가격: " . $this->data[$pos][self::BUY_PRICE] . "$, 판매갯수: "  . (is_numeric($this->data[$pos][self::SELL_AMOUNT]) ? $this->data[$pos][self::SELL_AMOUNT] . ", " : ($this->data[$pos][self::SELL_AMOUNT] == "?" ? "모두, " : $this->data[$pos][self::SELL_AMOUNT]) . ", 개당") . " 가격: " . $this->data[$pos][self::SELL_PRICE] . "$" : "Added the shop.\n" . Color::GOLD . "  [OneShop][$pos] ID: " . $this->data[$pos][self::ITEM_ID] . ", Buy Amount: "  . (is_numeric($this->data[$pos][self::BUY_AMOUNT]) ? $this->data[$pos][self::BUY_AMOUNT] . ", " : ($this->data[$pos][self::BUY_AMOUNT] == "?" ? "All, " : $this->data[$pos][self::BUY_AMOUNT]) . ", Unit") . " Price: " . $this->data[$pos][self::BUY_PRICE] . "$, Sell Amount: "  . (is_numeric($this->data[$pos][self::SELL_AMOUNT]) ? $this->data[$pos][self::SELL_AMOUNT] . ", " : ($this->data[$pos][self::SELL_AMOUNT] == "?" ? "All, " : $this->data[$pos][self::SELL_AMOUNT]) . ", Unit") . " Price: " . $this->data[$pos][self::SELL_PRICE] . "$");
											}
										}
									}
								}
							}else{
								$this->editTouch[$name] = [self::TOUCH_MODE => self::MODE_ADD, self::ITEM_ID => $id, self::BUY_AMOUNT => $sub[2], self::BUY_PRICE => $sub[3], self::SELL_AMOUNT => $sub[4], self::SELL_PRICE => $sub[5], self::LEVEL => floor($sub[6])];
								$r = Color::YELLOW . "[OneShop] " . ($ik ? "상점을 추가할 블럭을 터치해주세요." : "Touch the block to add shop.");
							}
						}
					}
				}
			break;
			case "remove":
			case "r":
			case "del":
			case "d":
			case "삭제":
			case "제거":
				if(!$sender->hasPermission("oneshop.cmd.remove")){
					$r = new Translation(Color::RED . "%commands.generic.permission");
				}elseif(isset($this->editTouch[$name = $sender->getName()]) && ($this->editTouch[$name][self::TOUCH_MODE] == self::MODE_REMOVE || $this->editTouch[$name][self::TOUCH_MODE] == self::MODE_REMOVEMODE)){
					unset($this->editTouch[$name]);
					$r = Color::YELLOW . "[OneShop] " . ($ik ? "상점 제거 모드가 해제됩니다." : "Shop remove mode is disabled");
				}elseif(!($isPlayer = $sender instanceof Player) || $isPlayer && isset($sub[3]) && $sub[1] != "" | $sub[2] != "" | $sub[3] != ""){
					if(!isset($sub[3]) || $sub[1] == "" | $sub[2] == "" | $sub[3] == ""){
						$r = Color::RED .  "Usage: /OneShop Remove(R) [X] [Y] [Z] [World]";
					}elseif(!is_numeric($sub[1])){
						$r = Color::RED . "[OneShop] $sub[1]" . ($ik ? "은(는) 잘못된 X좌표입니다." : " is invalid X coordinate");
					}elseif(!is_numeric($sub[2]) || ($sub[2] = floor($sub[2])) < 0 || ($sub[2] = floor($sub[2])) > 127){
						$r = Color::RED . "[OneShop] $sub[2]" . ($ik ? "은(는) 잘못된 Y좌표입니다." : " is invalid Y coordinate");
					}elseif(!is_numeric($sub[3])){
						$r = Color::RED . "[OneShop] $sub[3]" . ($ik ? "은(는) 잘못된 Z좌표입니다." : " is invalid Z coordinate");
					}else{
						if(isset($sub[4]) && ($level = $this->getServer()->getLevelByName($sub[4])) === null){
							$r = Color::RED . "[OneShop] $sub[4]" . ($ik ? "은(는) 잘못된 월드명입니다." : " is invalid world name");
						}elseif(!isset($sub[9])){
							$level = $isPlayer ? $sender->getLevel() : $this->getServer()->getDefaultLevel();
						}
						if(!isset($r)){
							if(!$level->isChunkLoaded($chunkX = ($sub[1] = floor($sub[1])) >> 4, $chunkZ = ($sub[3] = floor($sub[3])) >> 4) || !$level->isChunkGenerated($chunkX, $chunkZ)){
								$r = Color::RED . "[OneShop] $sub[1],$sub[2],$sub[3]" . ($ik ? "은(는) 잘못된 좌표입니다." : " is invalid coordinate");
							}else{
								if(!isset($this->data[$pos = "$sub[1]:$sub[2]:$sub[3]:" . $level->getFolderName()])){
									$r = Color::RED . "[OneShop] " . ($ik ? "그곳에는 상점이 없습니다." : "Shop is not exist in there");
								}else{
									$this->removeShop($pos);
									$r = Color::YELLOW . "[OneShop] " . ($ik ? "상점을 제거했습니다." : "Shop is removed.");
								}
							}
						}
					}
				}else{
					$this->editTouch[$name] = [self::TOUCH_MODE => isset($sub[1]) && $sub[1] != "" ? self::MODE_REMOVEMODE : self::MODE_REMOVE];
					$r = Color::YELLOW . "[OneShop] " . ($ik ? "제거할 상점을 터치해주세요." : "Touch the shop to remove.");
				}
			break;
			case "buildmode":
			case "build":
			case "edit":
			case "b":
			case "bm":
			case "e":
				if(!$sender->hasPermission("oneshop.cmd.buildmode")){
					$r = new Translation(Color::RED . "%commands.generic.permission");
				}elseif(!$sender instanceof Player){
					$r = Color::RED . "[OneShop] " . ($ik ? "게임내에서만 실행해주세요." : "Please run this command in-game");
				}elseif(isset($this->editTouch[$name = $sender->getName()]) && $this->editTouch[$name][self::TOUCH_MODE] === self::MODE_BUILD){
					unset($this->editTouch[$name]);
					$r = Color::YELLOW . "[OneShop] " . ($ik ? "건축 모드를 비활성화했습니다." : "Disable the build mode");
				}else{
					$this->editTouch[$name] = [self::TOUCH_MODE => self::MODE_BUILD];
					$r = Color::YELLOW . "[OneShop] " . ($ik ? "건축 모드를 활성화했습니다." : "Enable the build mode");
				}
			break;
			case "list":
			case "l":
			case "목록":
			case "리스트":
			break;
			case "reload":
				if(!$sender->hasPermission("oneshop.cmd.reload")){
					$r = new Translation(Color::RED . "%commands.generic.permission");
				}else{
					$this->loadData();
					$r = Color::YELLOW . "[OneShop] " . ($ik ? "데이터를 로드했습니다." : "Load thedata");
				}
			break;
			case "save":
				if(!$sender->hasPermission("oneshop.cmd.save")){
					$r = new Translation(Color::RED . "%commands.generic.permission");
				}else{
					$this->saveData();
					$r = Color::YELLOW . "[OneShop] " . ($ik ? "데이터를 저장했습니다." : "Save the data");
				}
			break;
			case "reset":
			case "리셋":
			case "초기화":
				if(!$sender->hasPermission("oneshop.cmd.reset")){
					$r = new Translation(Color::RED . "%commands.generic.permission");
				}else{
					foreach($this->data as $pos => $this->shopInfo){
						$this->removeShop($pos);
					}
					$this->saveData();
					$r = Color::YELLOW . "[OneShop] " . ($ik ? "데이터를 리셋했습니다." : "Reset the data");
				}
			break;
			default:
				if(isset($this->editTouch[$name = $sender->getName()])){
					$r = Color::YELLOW . "[OneShop] " . ($ik ? "상점 편집모드를 해제했습니다." : "Shop Edit Mode Disable");
					unset($this->editTouch[$name]);
				}else{
					return false;
				}
			break;
		}
		if(isset($r)) $sender->sendMessage($r);
		return true;
	}

	public function onBlockBreak(BlockBreakEvent $event){
		if((!isset($this->editTouch[$name = $event->getPlayer()->getName()]) || $this->editTouch[$name][self::TOUCH_MODE] !== self::MODE_BUILD) && isset($this->data[$this->pos2str($event->getBlock())])){
			$event->setCancelled();
 		}
	}

	public function onBlockPlace(BlockPlaceEvent $event){
		if((!isset($this->editTouch[$name = $event->getPlayer()->getName()]) || $this->editTouch[$name][self::TOUCH_MODE] !== self::MODE_BUILD) && isset($this->data[$this->pos2str($event->getBlock())]) || isset($this->placed[$name])){
			$event->setCancelled();
			if(isset($this->placed[$name])){
				unset($this->placed[$name]);
			}
 		}
	}

	public function onPlayerInteract(PlayerInteractEvent $event){
		$player = $event->getPlayer();
 		if((!isset($this->editTouch[$name = $player->getName()]) || $this->editTouch[$name][self::TOUCH_MODE] !== self::MODE_BUILD) && $event->getAction() == 1){ //$event->getAction() == PlayerInteractEvent::RIGHT_CLICK_BLOCK (== 1)
			$block = $event->getBlock();
			$pos = $this->pos2str($block);
			$ik = $this->isKorean();
			if(isset($this->editTouch[$name])){
				switch($this->editTouch[$name][self::TOUCH_MODE]){
					case self::MODE_ADD:
						if($this->editTouch[$name][self::TOUCH_MODE] === self::MODE_ADD && $block->getID() !== 20){
							$pos = $this->pos2str($block->getSide($event->getFace()));
						}
						if(isset($this->data[$pos])){
							$player->sendMessage(Color::RED . "[OneShop] " . ($ik ? "이곳에는 이미 상점이 있습니다." : "Shop is already exist in here"));
						}else{
							$this->addShop($pos, $this->editTouch[$name]);
							unset($this->editTouch[$name]);
							$player->sendMessage(Color::YELLOW . "[OneShop] " . ($ik ? 
								"상점을 추가했습니다.\n" . Color::GOLD . "  [OneShop][$pos] 아이디: " . $this->data[$pos][self::ITEM_ID] . ", 구매갯수: "  . (is_numeric($this->data[$pos][self::BUY_AMOUNT]) ? $this->data[$pos][self::BUY_AMOUNT] . ", " : ($this->data[$pos][self::BUY_AMOUNT] == "?" ? "모두, " : $this->data[$pos][self::BUY_AMOUNT]) . ", 개당") . " 가격: " . $this->data[$pos][self::BUY_PRICE] . "$, 판매갯수: "  . (is_numeric($this->data[$pos][self::SELL_AMOUNT]) ? $this->data[$pos][self::SELL_AMOUNT] . ", " : ($this->data[$pos][self::SELL_AMOUNT] == "?" ? "모두, " : $this->data[$pos][self::SELL_AMOUNT]) . ", 개당") . " 가격: " . $this->data[$pos][self::SELL_PRICE] . "$, 레벨: " . $this->data[$pos][self::LEVEL] : 
								"Added the shop.\n" . Color::GOLD . "  [OneShop][$pos] ID: " . $this->data[$pos][self::ITEM_ID] . ", Buy Amount: "  . (is_numeric($this->data[$pos][self::BUY_AMOUNT]) ? $this->data[$pos][self::BUY_AMOUNT] . ", " : ($this->data[$pos][self::BUY_AMOUNT] == "?" ? "All, " : $this->data[$pos][self::BUY_AMOUNT]) . ", Unit") . " Price: " . $this->data[$pos][self::BUY_PRICE] . "$, Sell Amount: "  . (is_numeric($this->data[$pos][self::SELL_AMOUNT]) ? $this->data[$pos][self::SELL_AMOUNT] . ", " : ($this->data[$pos][self::SELL_AMOUNT] == "?" ? "All, " : $this->data[$pos][self::SELL_AMOUNT]) . ", Unit") . " Price: " . $this->data[$pos][self::SELL_PRICE] . "$, Level: " . $this->data[$pos][self::LEVEL]
							));
						}
					break;
					case self::MODE_REMOVE:
						if(!isset($this->data[$pos]) && !isset($this->data[$pos = $this->pos2str($block->getSide($event->getFace()))])){
							$player->sendMessage(Color::RED . "[OneShop] " . ($ik ? "이곳에는 상점이 없습니다." : "Shop is not exist in here"));
						}else{
							$this->removeShop($pos);
							unset($this->editTouch[$name]);
							$player->sendMessage(Color::YELLOW . "[OneShop] " . ($ik ? "상점을 제거했습니다." : "Removed the shop."));
						}
					break;
					case self::MODE_REMOVEMODE:
						if(!isset($this->data[$pos]) && !isset($this->data[$pos = $this->pos2str($block->getSide($event->getFace()))])){
							$player->sendMessage(Color::RED . "[OneShop] " . ($ik ? "이곳에는 상점이 없습니다." : "Shop is not exist in here"));
						}else{
							$this->removeShop($pos);
							$player->sendMessage(Color::YELLOW . "[OneShop] " . ($ik ? "상점이 제거되었습니다.\n" . Color::YELLOW . "[OneShop] 다음 상점을 터치하시거나 명령어를 다시 입력해 제거모드를 종료해주세요." : "Shop is deleted \n" . Color::YELLOW . "[OneShop] Touch the next shop to delete or re-enter the command to disable the delete mode"));
						}
					break;
				}
				$event->setCancelled();
				if($this->isNewAPI() && $event->getItem()->canBePlaced()){
					$this->placed[$name] = true;
 				}elseif(!$this->isNewAPI() && $event->getItem()->isPlaceable()){
					$this->placed[$name] = true;
				}
			}elseif(isset($this->data[$pos]) || isset($this->data[$pos = $this->pos2str($block->getSide($event->getFace()))])){
				if($this->data[$pos][self::BUY_AMOUNT] == "-" && $this->data[$pos][self::SELL_AMOUNT] == "-"){
					return;
				}else{
					$player->getLevel()->addSound(new \pocketmine\level\sound\ClickSound($player->add(0, 10, 0)), [$player]);
					if($player->isCreative()){
						$player->sendMessage(Color::RED . "[OneShop] " . ($ik ? 
							"당신은 크리에이티브모드입니다.\n" . Color::DARK_RED . "  [OneShop][$pos] 아이디: " . $this->data[$pos][self::ITEM_ID] . ", 구매갯수: "  . (is_numeric($this->data[$pos][self::BUY_AMOUNT]) ? $this->data[$pos][self::BUY_AMOUNT] . ", " : ($this->data[$pos][self::BUY_AMOUNT] == "?" ? "모두, " : $this->data[$pos][self::BUY_AMOUNT]) . ", 개당") . " 가격: " . $this->data[$pos][self::BUY_PRICE] . "$, 판매갯수: "  . (is_numeric($this->data[$pos][self::SELL_AMOUNT]) ? $this->data[$pos][self::SELL_AMOUNT] . ", " : ($this->data[$pos][self::SELL_AMOUNT] == "?" ? "모두, " : $this->data[$pos][self::SELL_AMOUNT]) . ", 개당") . " 가격: " . $this->data[$pos][self::SELL_PRICE] . "$, 레벨: " . $this->data[$pos][self::LEVEL] : 
							"You are creative mode.\n" . Color::DARK_RED . "  [OneShop][$pos] ID: " . $this->data[$pos][self::ITEM_ID] . ", Buy Amount: "  . (is_numeric($this->data[$pos][self::BUY_AMOUNT]) ? $this->data[$pos][self::BUY_AMOUNT] . ", " : ($this->data[$pos][self::BUY_AMOUNT] == "?" ? "All, " : $this->data[$pos][self::BUY_AMOUNT]) . ", Unit") . " Price: " . $this->data[$pos][self::BUY_PRICE] . "$, Sell Amount: "  . (is_numeric($this->data[$pos][self::SELL_AMOUNT]) ? $this->data[$pos][self::SELL_AMOUNT] . ", " : ($this->data[$pos][self::SELL_AMOUNT] == "?" ? "All, " : $this->data[$pos][self::SELL_AMOUNT]) . ", Unit") . " Price: " . $this->data[$pos][self::SELL_PRICE] . "$, Level: " . $this->data[$pos][self::LEVEL]
						));
					}else{
						if(!isset($this->shopTouch[$name])){
							$this->shopTouch[$name] = [self::INFO_TIME => 0, self::TOUCH_POS => null, self::TOUCH_MODE => null];
						}
						$id = explode(":", $this->data[$pos][self::ITEM_ID]);
						$item = $event->getItem();
						$shopItem = Item::get($id[0], $id[1] == "?" ? null : $id[1]);
						$money = $this->getMoney($player);
						$inventory = $player->getInventory();
						$count = 0;
						$maxAmount = 0;
						for($i = 0; $i < $inventory->getSize(); ++$i){
							$slot = $inventory->getItem($i);
							if($item->equals($slot, $item->getDamage() !== null, true)){
								$count += $slot->getCount();
								$maxAmount += $item->getMaxStackSize() - $slot->getCount();
							}elseif($slot->getID() == 0 || $slot->getCount() == 0){
								$maxAmount += $item->getMaxStackSize();
							}
						}
 						if(($shopMode = $item->equals($shopItem, $shopItem->getDamage() !== null) ? self::MODE_SELL : self::MODE_BUY) != $this->shopTouch[$name][self::TOUCH_MODE] && $pos == $this->shopTouch[$name][self::TOUCH_POS] && microtime(true) - $this->shopTouch[$name][self::INFO_TIME] < 10){
		 					$this->shopTouch[$name][self::INFO_TIME] = microtime(true);
							$player->sendMessage(Color::RED . "[OneShop] " . ($ik ? "천천히 눌러주시기바랍니다." : "Please slow down"));
						}elseif($this->shopTouch[$name][self::TOUCH_MODE] === null || $this->shopTouch[$name][self::TOUCH_POS] === null || $pos !== $this->shopTouch[$name][self::TOUCH_POS] || microtime(true) - $this->shopTouch[$name][self::INFO_TIME] > 1){
		 					$this->shopTouch[$name][self::TOUCH_MODE] = $shopMode;
		 					$this->shopTouch[$name][self::INFO_TIME] = microtime(true);
		 					$this->shopTouch[$name][self::TOUCH_POS] = $pos;
		 					if($shopMode == self::MODE_SELL){
		 						if($this->data[$pos][self::SELL_AMOUNT] == "-"){
		 							$this->shopTouch[$name][self::INFO_TIME] = 0;
		 							$player->sendMessage(Color::RED . "[OneShop] " . ($ik ? "이 상점에서는 판매가 불가능합니다." : "This shop is can't sell item"));
		 						}else{
									$player->sendMessage(Color::YELLOW . "[OneShop] " . ($ik ? 
										"판매하시려면 다시 눌러주세요.\n" . Color::YELLOW . "  [OneShop] Lv." . $this->data[$pos][self::LEVEL] . ", 아이디: " . $this->data[$pos][self::ITEM_ID] . ", 갯수: "  . (is_numeric($this->data[$pos][self::SELL_AMOUNT]) ? $this->data[$pos][self::SELL_AMOUNT] . ", " : ($this->data[$pos][self::SELL_AMOUNT] == "?" ? "모두, " : $this->data[$pos][self::SELL_AMOUNT]) . ", 개당") . " 가격: " . $this->data[$pos][self::SELL_PRICE] . "$, 소유갯수: $count" : 
										"Touch twice to sell item.\n" . Color::YELLOW . "  [OneShop] Lv." . $this->data[$pos][self::LEVEL] . ", ID: " . $this->data[$pos][self::ITEM_ID] . ", Amount: "  . (is_numeric($this->data[$pos][self::SELL_AMOUNT]) ? $this->data[$pos][self::SELL_AMOUNT] . ", " : ($this->data[$pos][self::SELL_AMOUNT] == "?" ? "All, " : $this->data[$pos][self::SELL_AMOUNT]) . ", Unit") . " Price: " . $this->data[$pos][self::SELL_PRICE] . "$, You have: $count"
	 								));
	 							}
							}else{
		 						if($this->data[$pos][self::BUY_AMOUNT] == "-"){
		 							$this->shopTouch[$name][self::INFO_TIME] = 0;
		 							$player->sendMessage(Color::RED . "[OneShop] " . ($ik ? "이 상점에서는 구매가 불가능합니다." : "This shop is can't buy item"));
		 						}else{
									$player->sendMessage(Color::YELLOW . "[OneShop] " . ($ik ? 
										"구매하시려면 다시 눌러주세요.\n" . Color::YELLOW . "  [OneShop] Lv." . $this->data[$pos][self::LEVEL] . ", 아이디: " . $this->data[$pos][self::ITEM_ID] . ", 갯수: "  . (is_numeric($this->data[$pos][self::BUY_AMOUNT]) ? $this->data[$pos][self::BUY_AMOUNT] . ", " : ($this->data[$pos][self::BUY_AMOUNT] == "?" ? "모두, " : $this->data[$pos][self::BUY_AMOUNT]) . ", 개당") . " 가격: " . $this->data[$pos][self::BUY_PRICE] . "$, 나의 돈: $money, 최대 구매량: $maxAmount" : 
										"Touch twice to buy item.\n" . Color::YELLOW . "  [OneShop] Lv." . $this->data[$pos][self::LEVEL] . ", ID: " . $this->data[$pos][self::ITEM_ID] . ", Amount: "  . (is_numeric($this->data[$pos][self::BUY_AMOUNT]) ? $this->data[$pos][self::BUY_AMOUNT] . ", " : ($this->data[$pos][self::BUY_AMOUNT] == "?" ? "All, " : $this->data[$pos][self::BUY_AMOUNT]) . ", Unit") . " Price: " . $this->data[$pos][self::BUY_PRICE] . "$, Your money: $money, Max Amount: $maxAmount"
									));
								}
							}
 						}elseif(($level = (int) $this->getLevel($player)) < $this->data[$pos][self::LEVEL]){
 							$player->sendMessage(Color::RED . "[OneShop] " . ($ik ? 
 								"농장의 레벨이 부족합니다. 필요레벨: " . $this->data[$pos][self::LEVEL] . ", 당신의 레벨: $level" :
 								"You has less farm level than its need level. NeedLevel: " . $this->data[$pos][self::LEVEL] . ", Your Level: $level"
 							));
 						}else{
							$this->shopTouch[$name][self::TOUCH_MODE] = $shopMode;
		 					$this->shopTouch[$name][self::INFO_TIME] = microtime(true);
		 					$this->shopTouch[$name][self::TOUCH_POS] = $pos;
							switch($shopMode){
								case self::MODE_BUY:
									if($this->data[$pos][self::BUY_AMOUNT] == "?"){
										$buyAmount = min($money != 0 && $this->data[$pos][self::BUY_PRICE] != 0 ? floor($money / $this->data[$pos][self::BUY_PRICE]) : $maxAmount, $maxAmount);
										$buyPrice = $this->data[$pos][self::BUY_PRICE] * $buyAmount;
										$needMoney = $this->data[$pos][self::BUY_PRICE];
									}elseif(strpos($this->data[$pos][self::BUY_AMOUNT], "~") !== false){
										$countRange = explode("~", $this->data[$pos][self::BUY_AMOUNT]);
										if($countRange[1] == 0){
											$countRange[1] = PHP_INT_MAX;
										}
										$buyAmount = min($money >= $this->data[$pos][self::BUY_PRICE] && $this->data[$pos][self::BUY_PRICE] != 0 ? ($money - ($money % $this->data[$pos][self::BUY_PRICE])) / $this->data[$pos][self::BUY_PRICE] : $countRange[0], $countRange[1]);
										$buyPrice = $this->data[$pos][self::BUY_PRICE] * $buyAmount;
										$needMoney = $countRange[0] * $this->data[$pos][self::BUY_PRICE];
									}else{
										$buyAmount = $this->data[$pos][self::BUY_AMOUNT];
										$buyPrice = $this->data[$pos][self::BUY_PRICE];
										$needMoney = $this->data[$pos][self::BUY_PRICE];
									}
									if($buyAmount > $maxAmount || $maxAmount == 0){
										$player->sendMessage(Color::RED . "[OneShop] " . ($ik ? "인벤토리가 가득찼습니다." : "You has less inventory than its count"));
									}elseif($money < $needMoney || $buyAmount == 0){
										$player->sendMessage(Color::RED . "[OneShop] " . ($ik ? "돈이 부족합니다.\n" . Color::RED . "  [OneShop] 나의 돈 : $money, 필요금액: " : "You has less money than its price\n" . Color::RED . "  [OneShop] Your money : $money, Need : ") . $needMoney);
									}else{
										$this->giveMoney($player, -$buyPrice);
										$shopItem->setCount($buyAmount);
										$inventory->addItem($shopItem);
										$player->sendMessage(Color::YELLOW . "[OneShop] " . ($ik ? "아이템을 구매하셨습니다. [갯수 : $buyAmount, 가격 : $buyPrice$, 나의 돈 : " . ($money - $buyPrice) . "$" : "You bought Item. [Count: $buyAmount, Price: $buyPrice, My money: " . ($money - $buyPrice) . "$"));
									}
								break;
								case self::MODE_SELL:
									if($this->data[$pos][self::SELL_AMOUNT] == "?"){
										$sellAmount = $count;
										$sellPrice = $this->data[$pos][self::SELL_PRICE] * $sellAmount;
										$needCount = 1;
									}elseif(strpos($this->data[$pos][self::SELL_AMOUNT], "~") !== false){
										$countRange = explode("~", $this->data[$pos][self::SELL_AMOUNT]);
										if($countRange[1] == 0){
											$countRange[1] = PHP_INT_MAX;
										}
										$needCount = $countRange[0];
										$sellAmount = min($count, $countRange[1]);
										$sellPrice = $this->data[$pos][self::SELL_PRICE] * $sellAmount;
									}else{
										$sellAmount = $this->data[$pos][self::SELL_AMOUNT];
										$sellPrice = $this->data[$pos][self::SELL_PRICE];
										$needCount = $this->data[$pos][self::SELL_AMOUNT];
									}
									if($count < $needCount){
										$player->sendMessage(Color::RED . "[OneShop] " . ($ik ? "아이템이 부족합니다.\n" . Color::RED . "  [OneShop] 소유갯수 : $count, 필요량 : " : "You has less Item than its count\n" . Color::RED . "  [OneShop] You have : $count, Need : ") . $needCount);
									}else{
										$this->giveMoney($player, $sellPrice);
										$shopItem->setCount($sellAmount);
										$inventory->removeItem($shopItem);
										$player->sendMessage(Color::YELLOW . "[OneShop] " . ($ik ? "아이템을 판매하셨습니다. [갯수 : $sellAmount, 가격 : $sellPrice$, 나의 돈 : " . ($money + $sellPrice) . "$" : "You sell Item. [Count: $sellAmount, Price: $sellPrice, My money: " . ($money + $sellPrice) . "$"));
									}
								break;
							}
						}
					}
 					$event->setCancelled();
					if(!$this->isNewAPI() && $event->getItem()->isPlaceable()){
						$this->placed[$name] = true;
					}
				}
			}
		}
	}

	/**
	 * @priority HIGHEST
	 */
	public function onServerCommandProcess(ServerCommandEvent $event){
		if(!$event->isCancelled() && stripos("save-all", $command = $event->getCommand()) === 0){
			$this->checkSaveAll($event->getSender());
		}
	}

	/**
	 * @priority HIGHEST
	 */
	public function onRemoteServerCommand(RemoteServerCommandEvent $event){
		if(!$event->isCancelled() && stripos("save-all", $command = $event->getCommand()) === 0){
			$this->checkSaveAll($event->getSender());
		}
	}

	/**
	 * @priority HIGHEST
	 */
	public function onPlayerCommandPreprocess(PlayerCommandPreprocessEvent $event){
		if(!$event->isCancelled() && stripos("/save-all", $command = $event->getMessage()) === 0){
			$this->checkSaveAll($event->getPlayer());
		}
	}

	public function checkSaveAll(CommandSender $sender){
		if(($command =  $this->getServer()->getCommandMap()->getCommand("save-all")) instanceof Command && $command->testPermissionSilent($sender)){
			$this->saveData();
			$sender->sendMessage(Color::YELLOW . "[OneShop] Saved data.");
		}
	}

 	public function onAsyncRun(){
 		foreach($this->data as $posKey => $shopInfo){
			if(!isset($this->spawned[$posKey])){
				$this->spawned[$posKey] = [];
			}
			if(!isset($this->eids[$posKey])){
				$this->eids[$posKey] = Entity::$entityCount++;
				Entity::$entityCount++;
			}
			if(!isset($this->packets[$posKey])){
				$this->packets[$posKey] = [];
			}
			$pos = explode(":", $posKey);
			foreach($this->getServer()->getOnlinePlayers() as $player){
				if($player->spawned && strtolower($player->getLevel()->getFolderName()) === $pos[3] && ($distance = sqrt(pow($dX = ($x = $pos[0]) - $player->x, 2) + pow(($y = $pos[1]) - $player->y, 2) + pow($dZ = ($z = $pos[2]) - $player->z, 2))) < 200){
					if(!isset($this->spawned[$posKey][$name = $player->getName()])){
		 				if(!isset($this->packets[$posKey][self::PACKET_ADD_ITEM_ENTITY])){
			 				$this->addItemEntityPk->eid = $this->eids[$posKey];
							$this->addItemEntityPk->item = new item(...explode(":", $shopInfo[self::ITEM_ID]));
							$this->packets[$posKey][self::PACKET_ADD_ITEM_ENTITY] = clone $this->addItemEntityPk;
						}
						if(!isset($this->packets[$posKey][self::PACKET_ADD_ENTITY])){
							$this->addEntityPk->eid = $this->eids[$posKey] + 1;
							$this->addEntityPk->x = $pos[0] + 0.5;
							$this->addEntityPk->y = $pos[1];
							$this->addEntityPk->z = $pos[2] + 0.5;
							$this->addEntityPk->metadata[Entity::DATA_NAMETAG] = [
								Entity::DATA_TYPE_STRING, Color::GREEN . ($this->isKorean() ?
									"Lv." . $shopInfo[self::LEVEL] . "  " . $shopInfo[self::ITEM_ID] . "\n" . Color::AQUA . ($shopInfo[self::BUY_AMOUNT] == "-" ? "" : (is_numeric($shopInfo[self::BUY_AMOUNT]) ? $shopInfo[self::BUY_AMOUNT] . " => " : ($shopInfo[self::BUY_AMOUNT] == "?" ? "" : $shopInfo[self::BUY_AMOUNT] . " => ") . "개당") . " " . $shopInfo[self::BUY_PRICE] . "$") . ($shopInfo[self::SELL_AMOUNT] == "-" ? "" : "\n" . Color::DARK_AQUA . (is_numeric($shopInfo[self::SELL_AMOUNT]) ? $shopInfo[self::SELL_AMOUNT] . " => " : ($shopInfo[self::SELL_AMOUNT] == "?" ? "" : $shopInfo[self::SELL_AMOUNT] . " => ") . "개당") . " " . $shopInfo[self::SELL_PRICE] . "$") : 
									"Lv." . $shopInfo[self::LEVEL] . "  " . $shopInfo[self::ITEM_ID] . "\n" . Color::AQUA . ($shopInfo[self::BUY_AMOUNT] == "-" ? "" : (is_numeric($shopInfo[self::BUY_AMOUNT]) ? $shopInfo[self::BUY_AMOUNT] . " => " : ($shopInfo[self::BUY_AMOUNT] == "?" ? "" : $shopInfo[self::BUY_AMOUNT] . " => ") . "Unit") . " " . $shopInfo[self::BUY_PRICE] . "$") . ($shopInfo[self::SELL_AMOUNT] == "-" ? "" :  "\n" . Color::DARK_AQUA . (is_numeric($shopInfo[self::SELL_AMOUNT]) ? $shopInfo[self::SELL_AMOUNT] . " => " : ($shopInfo[self::SELL_AMOUNT] == "?" ? "" : $shopInfo[self::SELL_AMOUNT] . " => ") . "Unit") . " " . $shopInfo[self::SELL_PRICE] . "$")
							)
							];
							$this->packets[$posKey][self::PACKET_ADD_ENTITY] = clone $this->addEntityPk;
						}
						if(!isset($this->packets[$posKey][self::PACKET_SET_ENTITY_LINK])){
							$this->setEntityLinkPk->from = $this->eids[$posKey] + 1;
							$this->setEntityLinkPk->to = $this->eids[$posKey];
							$this->packets[$posKey][self::PACKET_SET_ENTITY_LINK] = clone $this->setEntityLinkPk;
						}
						$player->dataPacket($this->packets[$posKey][self::PACKET_ADD_ITEM_ENTITY]);
						$player->dataPacket($this->packets[$posKey][self::PACKET_ADD_ENTITY]);
						$player->dataPacket($this->packets[$posKey][self::PACKET_SET_ENTITY_LINK]);
						$this->spawned[$posKey][$name] = [$player, false, self::INFO_TIME => time(true)];
					}elseif(isset($this->spawned[$posKey][$name][self::INFO_TIME]) && isset($this->spawned[$posKey][$name][self::INFO_TIME]) && time(true) - $this->spawned[$posKey][$name][self::INFO_TIME] > 60){
						if(!isset($this->packets[$posKey][self::PACKET_REMOVE_ENTITY_FIRST])){
							$this->removeEntityPk->eid = $this->eids[$posKey];
							$this->packets[$posKey][self::PACKET_REMOVE_ENTITY_FIRST] = clone $this->removeEntityPk;
						}
						if(!isset($this->packets[$posKey][self::PACKET_REMOVE_ENTITY_SECOND])){
							$this->removeEntityPk->eid = $this->eids[$posKey] + 1;
							$this->packets[$posKey][self::PACKET_REMOVE_ENTITY_SECOND] = clone $this->removeEntityPk;
						}
		 				$player->dataPacket($this->packets[$posKey][self::PACKET_REMOVE_ENTITY_FIRST]);
		 				$player->dataPacket($this->packets[$posKey][self::PACKET_REMOVE_ENTITY_SECOND]);
						$player->dataPacket($this->packets[$posKey][self::PACKET_ADD_ITEM_ENTITY]);
						$player->dataPacket($this->packets[$posKey][self::PACKET_ADD_ENTITY]);
						$player->dataPacket($this->packets[$posKey][self::PACKET_SET_ENTITY_LINK]);
						$this->spawned[$posKey][$name][self::INFO_TIME] = time(true);
					}
				}elseif(isset($this->spawned[$posKey][$name = $player->getName()])){
					if(!isset($this->packets[$posKey][self::PACKET_REMOVE_ENTITY_FIRST])){
						$this->removeEntityPk->eid = $this->eids[$posKey];
						$this->packets[$posKey][self::PACKET_REMOVE_ENTITY_FIRST] = clone $this->removeEntityPk;
					}
					if(!isset($this->packets[$posKey][self::PACKET_REMOVE_ENTITY_SECOND])){
						$this->removeEntityPk->eid = $this->eids[$posKey] + 1;
						$this->packets[$posKey][self::PACKET_REMOVE_ENTITY_SECOND] = clone $this->removeEntityPk;
					}
	 				$player->dataPacket($this->packets[$posKey][self::PACKET_REMOVE_ENTITY_FIRST]);
	 				$player->dataPacket($this->packets[$posKey][self::PACKET_REMOVE_ENTITY_SECOND]);
					unset($this->spawned[$posKey][$name]);
	  			}
	  			if(isset($this->spawned[$posKey][$name]) && ($shopInfo[self::BUY_AMOUNT] != "-" || $shopInfo[self::SELL_AMOUNT] != "-")){
	  				if($distance < 3){
	  					if($this->spawned[$posKey][$name][1] === false){
							if(!isset($this->packets[$posKey][self::PACKET_SET_ENTITY_DATA_SHOW])){
								$this->setEntityDataPk->eid = $this->eids[$posKey] + 1;
								$this->setEntityDataPk->metadata = [Entity::DATA_SHOW_NAMETAG => [Entity::DATA_TYPE_BYTE, true]];
								$this->packets[$posKey][self::PACKET_SET_ENTITY_DATA_SHOW] = clone $this->setEntityDataPk;
							}
							$player->dataPacket($this->packets[$posKey][self::PACKET_SET_ENTITY_DATA_SHOW]);
			 				$this->spawned[$posKey][$name][1] = true;
			 			}
	  				}else{
	  					if($this->spawned[$posKey][$name][1] === true){
							if(!isset($this->packets[$posKey][self::PACKET_SET_ENTITY_DATA_HIDE])){
								$this->setEntityDataPk->eid = $this->eids[$posKey] + 1;
								$this->setEntityDataPk->metadata = [Entity::DATA_SHOW_NAMETAG => [Entity::DATA_TYPE_BYTE, false]];
								$this->packets[$posKey][self::PACKET_SET_ENTITY_DATA_HIDE] = clone $this->setEntityDataPk;
							}
			 				$player->dataPacket($this->packets[$posKey][self::PACKET_SET_ENTITY_DATA_HIDE]);
			 				$this->spawned[$posKey][$name][1] = false;
			 			}
	  				}
				}
			}
		}
	}

	public function addShop($pos, $info){
		if(!isset($this->data[$pos])){
			$this->data[$pos] = $info;
			$pos = explode(":", $pos);
			if(($level = $this->getServer()->getLevelByName($pos[3])) != false) $level->setBlock(new Vector3($pos[0], $pos[1], $pos[2]), Block::get(20));
		}
	}

	public function removeShop($pos){
		if(isset($this->data[$pos])){
			if(isset($this->spawned[$pos])){
				$this->removeEntityPk->eid = $this->eids[$pos];
				foreach($this->spawned[$pos] as $value){
					$value[0]->dataPacket($this->removeEntityPk);
				}
				$this->removeEntityPk->eid = $this->eids[$pos] + 1;
				foreach($this->spawned[$pos] as $value){
					$value[0]->dataPacket($this->removeEntityPk);
				}
			}
		}
		unset($this->spawned[$pos]);
		unset($this->packets[$pos]);
 		unset($this->data[$pos]);
	}

	public function pos2str(\pocketmine\level\Position $pos){
		return floor($pos->x) . ":" . floor($pos->y) . ":" . floor($pos->z) . ":" . $pos->getLevel()->getFolderName();
	}

	public function getMoney($player){
		if(!$this->money){
			return false;
		}else{
			if($player instanceof Player){
				$player = $player->getName();
			}elseif(!is_string($player)){
				return false;
			}
			switch($this->money->getName()){
				case "PocketMoney":
				case "MassiveEconomy":
				case "Money":
					return $this->money->getMoney($player);
				break;
				case "EconomyAPI":
					return $this->money->mymoney($player);
				break;
				default:
					return false;
				break;
			}
		}
	}

	public function getLevel($player){
		if(!$this->farm){
			return false;
		}else{
			if($player instanceof Player){
				$player = $player->getName();
			}elseif(!is_string($player)){
				return false;
			}
			return $this->farm->getLevel($player);
		}
	}

	public function giveMoney($player, $money){
		if(!$this->money){
			return false;
		}else{
			if($player instanceof Player){
				$player = $player->getName();
			}elseif(!is_string($player) || !is_numeric($money) || ($money = floor($money)) <= 0){
				return false;
			}
			switch($this->money->getName()){
				case "PocketMoney":
					$this->money->grantMoney($player, $money);
				break;
				case "EconomyAPI":
					$this->money->setMoney($player, $this->money->mymoney($player) + $money);
				break;
				case "MassiveEconomy":
				case "Money":
					$this->money->setMoney($player, $this->money->getMoney($player) + $money);
				break;
				default:
					return false;
				break;
			}
			return true;
		}
	}

	public function loadData(){
		@mkdir($folder = $this->getDataFolder());
		if(!file_exists($path = $folder . "Shops.sl")){	
			file_put_contents($path, serialize([]));
		}
		$this->data = unserialize(file_get_contents($path));
	}

	public function saveData(){
		@mkdir($folder = $this->getDataFolder());
		file_put_contents($folder . "Shops.sl", serialize($this->data));
	}

	public function isKorean(){
		return $this->getServer()->getLanguage()->getName() == "\"한국어\"";
	}

	public function isNewAPI(){
		return $this->getServer()->getApiVersion() !== "1.12.0";
	}
}