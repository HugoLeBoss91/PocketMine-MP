<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\data\bedrock\item;

use pocketmine\block\Block;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\utils\SkullType;
use pocketmine\block\VanillaBlocks as Blocks;
use pocketmine\data\bedrock\BlockItemIdMap;
use pocketmine\data\bedrock\blockstate\BlockStateSerializeException;
use pocketmine\data\bedrock\CompoundTypeIds;
use pocketmine\data\bedrock\DyeColorIdMap;
use pocketmine\data\bedrock\item\ItemTypeIds as Ids;
use pocketmine\data\bedrock\item\SavedItemData as Data;
use pocketmine\data\bedrock\PotionTypeIdMap;
use pocketmine\item\Banner;
use pocketmine\item\CoralFan;
use pocketmine\item\Item;
use pocketmine\item\ItemBlock;
use pocketmine\item\PotionType;
use pocketmine\item\VanillaItems as Items;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use function class_parents;
use function get_class;

final class ItemSerializer{
	/**
	 * These callables actually accept Item, but for the sake of type completeness, it has to be never, since we can't
	 * describe the bottom type of a type hierarchy only containing Item.
	 *
	 * @var \Closure[][]
	 * @phpstan-var array<int, array<class-string, \Closure(never) : Data>>
	 */
	private array $itemSerializers = [];

	/**
	 * @var \Closure[][]
	 * @phpstan-var array<int, array<class-string, \Closure(never) : Data>>
	 */
	private array $blockItemSerializers = [];

	public function __construct(){
		$this->registerSerializers();
	}

	/**
	 * @phpstan-template TItemType of Item
	 * @phpstan-param TItemType $item
	 * @phpstan-param \Closure(TItemType) : Data $serializer
	 */
	public function map(Item $item, \Closure $serializer) : void{
		if($item->hasAnyDamageValue()){
			throw new \InvalidArgumentException("Cannot serialize a recipe wildcard");
		}
		$index = $item->getTypeId();
		if(isset($this->itemSerializers[$index])){
			//TODO: REMOVE ME
			throw new AssumptionFailedError("Registering the same item twice!");
		}
		$this->itemSerializers[$index][get_class($item)] = $serializer;
	}

	/**
	 * @phpstan-template TBlockType of Block
	 * @phpstan-param TBlockType $block
	 * @phpstan-param \Closure(TBlockType) : Data $serializer
	 */
	public function mapBlock(Block $block, \Closure $serializer) : void{
		$index = $block->getTypeId();
		if(isset($this->blockItemSerializers[$index])){
			throw new AssumptionFailedError("Registering the same blockitem twice!");
		}
		$this->blockItemSerializers[$index][get_class($block)] = $serializer;
	}

	/**
	 * @phpstan-template TItemType of Item
	 * @phpstan-param TItemType $item
	 *
	 * @throws ItemTypeSerializeException
	 */
	public function serialize(Item $item) : Data{
		if($item->isNull()){
			throw new \InvalidArgumentException("Cannot serialize a null itemstack");
		}
		if($item instanceof ItemBlock){
			$data = $this->serializeBlockItem($item->getBlock());
		}else{
			$index = $item->getTypeId();

			$locatedSerializer = $this->itemSerializers[$index][get_class($item)] ?? null;
			if($locatedSerializer === null){
				$parents = class_parents($item);
				if($parents !== false){
					foreach($parents as $parent){
						if(isset($this->itemSerializers[$index][$parent])){
							$locatedSerializer = $this->itemSerializers[$index][$parent];
							break;
						}
					}
				}
			}

			if($locatedSerializer === null){
				throw new ItemTypeSerializeException("No serializer registered for " . get_class($item) . " " . $item->getName());
			}

			/**
			 * @var \Closure $serializer
			 * @phpstan-var \Closure(TItemType) : Data $serializer
			 */
			$serializer = $locatedSerializer;

			/** @var Data $data */
			$data = $serializer($item);
		}

		return $data;
	}

	/**
	 * @phpstan-template TBlockType of Block
	 * @phpstan-param TBlockType $block
	 *
	 * @throws ItemTypeSerializeException
	 */
	private function serializeBlockItem(Block $block) : Data{
		$index = $block->getTypeId();

		$locatedSerializer = $this->blockItemSerializers[$index][get_class($block)] ?? null;
		if($locatedSerializer === null){
			$parents = class_parents($block);
			if($parents !== false){
				foreach($parents as $parent){
					if(isset($this->blockItemSerializers[$index][$parent])){
						$locatedSerializer = $this->blockItemSerializers[$index][$parent];
						break;
					}
				}
			}
		}

		if($locatedSerializer !== null){
			/** @phpstan-var \Closure(TBlockType) : Data $serializer */
			$serializer = $locatedSerializer;
			$data = $serializer($block);
		}else{
			$data = self::standardBlock($block);
		}

		return $data;
	}

	/**
	 * @throws ItemTypeSerializeException
	 */
	private static function standardBlock(Block $block) : Data{
		try{
			$blockStateData = GlobalBlockStateHandlers::getSerializer()->serialize($block->getFullId());
		}catch(BlockStateSerializeException $e){
			throw new ItemTypeSerializeException($e->getMessage(), 0, $e);
		}

		$itemNameId = BlockItemIdMap::getInstance()->lookupItemId($blockStateData->getName()) ?? $blockStateData->getName();

		return new Data($itemNameId, 0, $blockStateData);
	}

	/**
	 * @phpstan-return \Closure() : Data
	 */
	private static function id(string $id) : \Closure{
		return fn() => new Data($id);
	}

	/**
	 * @phpstan-return \Closure() : Data
	 */
	private static function bed(DyeColor $color) : \Closure{
		$meta = DyeColorIdMap::getInstance()->toId($color);
		return fn() => new Data(Ids::BED, $meta);
	}

	/**
	 * @phpstan-return \Closure() : Data
	 */
	private static function skull(SkullType $skullType) : \Closure{
		$meta = $skullType->getMagicNumber();
		return fn() => new Data(Ids::SKULL, $meta);
	}

	/**
	 * @phpstan-return \Closure() : Data
	 */
	private static function chemical(int $type) : \Closure{
		return fn() => new Data(Ids::COMPOUND, $type);
	}

	/**
	 * @phpstan-return \Closure() : Data
	 */
	private static function potion(PotionType $type) : \Closure{
		$meta = PotionTypeIdMap::getInstance()->toId($type);
		return fn() => new Data(Ids::POTION, $meta);
	}

	/**
	 * @phpstan-return \Closure() : Data
	 */
	private static function splashPotion(PotionType $type) : \Closure{
		$meta = PotionTypeIdMap::getInstance()->toId($type);
		return fn() => new Data(Ids::SPLASH_POTION, $meta);
	}

	private function registerSpecialBlockSerializers() : void{
		$this->mapBlock(Blocks::ACACIA_DOOR(), self::id(Ids::ACACIA_DOOR));
		$this->mapBlock(Blocks::BIRCH_DOOR(), self::id(Ids::BIRCH_DOOR));
		$this->mapBlock(Blocks::BREWING_STAND(), self::id(Ids::BREWING_STAND));
		$this->mapBlock(Blocks::CAKE(), self::id(Ids::CAKE));
		$this->mapBlock(Blocks::DARK_OAK_DOOR(), self::id(Ids::DARK_OAK_DOOR));
		$this->mapBlock(Blocks::FLOWER_POT(), self::id(Ids::FLOWER_POT));
		$this->mapBlock(Blocks::HOPPER(), self::id(Ids::HOPPER));
		$this->mapBlock(Blocks::IRON_DOOR(), self::id(Ids::IRON_DOOR));
		$this->mapBlock(Blocks::ITEM_FRAME(), self::id(Ids::FRAME));
		$this->mapBlock(Blocks::JUNGLE_DOOR(), self::id(Ids::JUNGLE_DOOR));
		$this->mapBlock(Blocks::NETHER_WART(), self::id(Ids::NETHER_WART));
		$this->mapBlock(Blocks::OAK_DOOR(), self::id(Ids::WOODEN_DOOR));
		$this->mapBlock(Blocks::REDSTONE_COMPARATOR(), self::id(Ids::COMPARATOR));
		$this->mapBlock(Blocks::REDSTONE_REPEATER(), self::id(Ids::REPEATER));
		$this->mapBlock(Blocks::SPRUCE_DOOR(), self::id(Ids::SPRUCE_DOOR));
		$this->mapBlock(Blocks::SUGARCANE(), self::id(Ids::SUGAR_CANE));
	}

	private function registerSerializers() : void{
		$this->registerSpecialBlockSerializers();

		//these are encoded as regular blocks, but they have to be accounted for explicitly since they don't use ItemBlock
		//Bamboo->getBlock() returns BambooSapling :(
		$this->map(Items::BAMBOO(), fn() => self::standardBlock(Blocks::BAMBOO()));
		$this->map(Items::CORAL_FAN(), fn(CoralFan $item) => self::standardBlock($item->getBlock()));

		$this->map(Items::ACACIA_BOAT(), self::id(Ids::ACACIA_BOAT));
		$this->map(Items::ACACIA_SIGN(), self::id(Ids::ACACIA_SIGN));
		$this->map(Items::APPLE(), self::id(Ids::APPLE));
		$this->map(Items::ARROW(), self::id(Ids::ARROW));
		$this->map(Items::AWKWARD_POTION(), self::potion(PotionType::AWKWARD()));
		$this->map(Items::AWKWARD_SPLASH_POTION(), self::splashPotion(PotionType::AWKWARD()));
		$this->map(Items::BAKED_POTATO(), self::id(Ids::BAKED_POTATO));
		$this->map(Items::BANNER(), fn(Banner $item) => new Data(Ids::BANNER, DyeColorIdMap::getInstance()->toInvertedId($item->getColor())));
		$this->map(Items::BEETROOT(), self::id(Ids::BEETROOT));
		$this->map(Items::BEETROOT_SEEDS(), self::id(Ids::BEETROOT_SEEDS));
		$this->map(Items::BEETROOT_SOUP(), self::id(Ids::BEETROOT_SOUP));
		$this->map(Items::BIRCH_BOAT(), self::id(Ids::BIRCH_BOAT));
		$this->map(Items::BIRCH_SIGN(), self::id(Ids::BIRCH_SIGN));
		$this->map(Items::BLACK_BED(), self::bed(DyeColor::BLACK()));
		$this->map(Items::BLACK_DYE(), self::id(Ids::BLACK_DYE));
		$this->map(Items::BLAZE_POWDER(), self::id(Ids::BLAZE_POWDER));
		$this->map(Items::BLAZE_ROD(), self::id(Ids::BLAZE_ROD));
		$this->map(Items::BLEACH(), self::id(Ids::BLEACH));
		$this->map(Items::BLUE_BED(), self::bed(DyeColor::BLUE()));
		$this->map(Items::BLUE_DYE(), self::id(Ids::BLUE_DYE));
		$this->map(Items::BONE(), self::id(Ids::BONE));
		$this->map(Items::BONE_MEAL(), self::id(Ids::BONE_MEAL));
		$this->map(Items::BOOK(), self::id(Ids::BOOK));
		$this->map(Items::BOW(), self::id(Ids::BOW));
		$this->map(Items::BOWL(), self::id(Ids::BOWL));
		$this->map(Items::BREAD(), self::id(Ids::BREAD));
		$this->map(Items::BRICK(), self::id(Ids::BRICK));
		$this->map(Items::BROWN_BED(), self::bed(DyeColor::BROWN()));
		$this->map(Items::BROWN_DYE(), self::id(Ids::BROWN_DYE));
		$this->map(Items::BUCKET(), self::id(Ids::BUCKET));
		$this->map(Items::CARROT(), self::id(Ids::CARROT));
		$this->map(Items::CHAINMAIL_BOOTS(), self::id(Ids::CHAINMAIL_BOOTS));
		$this->map(Items::CHAINMAIL_CHESTPLATE(), self::id(Ids::CHAINMAIL_CHESTPLATE));
		$this->map(Items::CHAINMAIL_HELMET(), self::id(Ids::CHAINMAIL_HELMET));
		$this->map(Items::CHAINMAIL_LEGGINGS(), self::id(Ids::CHAINMAIL_LEGGINGS));
		$this->map(Items::CHARCOAL(), self::id(Ids::CHARCOAL));
		$this->map(Items::CHEMICAL_ALUMINIUM_OXIDE(), self::chemical(CompoundTypeIds::ALUMINIUM_OXIDE));
		$this->map(Items::CHEMICAL_AMMONIA(), self::chemical(CompoundTypeIds::AMMONIA));
		$this->map(Items::CHEMICAL_BARIUM_SULPHATE(), self::chemical(CompoundTypeIds::BARIUM_SULPHATE));
		$this->map(Items::CHEMICAL_BENZENE(), self::chemical(CompoundTypeIds::BENZENE));
		$this->map(Items::CHEMICAL_BORON_TRIOXIDE(), self::chemical(CompoundTypeIds::BORON_TRIOXIDE));
		$this->map(Items::CHEMICAL_CALCIUM_BROMIDE(), self::chemical(CompoundTypeIds::CALCIUM_BROMIDE));
		$this->map(Items::CHEMICAL_CALCIUM_CHLORIDE(), self::chemical(CompoundTypeIds::CALCIUM_CHLORIDE));
		$this->map(Items::CHEMICAL_CERIUM_CHLORIDE(), self::chemical(CompoundTypeIds::CERIUM_CHLORIDE));
		$this->map(Items::CHEMICAL_CHARCOAL(), self::chemical(CompoundTypeIds::CHARCOAL));
		$this->map(Items::CHEMICAL_CRUDE_OIL(), self::chemical(CompoundTypeIds::CRUDE_OIL));
		$this->map(Items::CHEMICAL_GLUE(), self::chemical(CompoundTypeIds::GLUE));
		$this->map(Items::CHEMICAL_HYDROGEN_PEROXIDE(), self::chemical(CompoundTypeIds::HYDROGEN_PEROXIDE));
		$this->map(Items::CHEMICAL_HYPOCHLORITE(), self::chemical(CompoundTypeIds::HYPOCHLORITE));
		$this->map(Items::CHEMICAL_INK(), self::chemical(CompoundTypeIds::INK));
		$this->map(Items::CHEMICAL_IRON_SULPHIDE(), self::chemical(CompoundTypeIds::IRON_SULPHIDE));
		$this->map(Items::CHEMICAL_LATEX(), self::chemical(CompoundTypeIds::LATEX));
		$this->map(Items::CHEMICAL_LITHIUM_HYDRIDE(), self::chemical(CompoundTypeIds::LITHIUM_HYDRIDE));
		$this->map(Items::CHEMICAL_LUMINOL(), self::chemical(CompoundTypeIds::LUMINOL));
		$this->map(Items::CHEMICAL_MAGNESIUM_NITRATE(), self::chemical(CompoundTypeIds::MAGNESIUM_NITRATE));
		$this->map(Items::CHEMICAL_MAGNESIUM_OXIDE(), self::chemical(CompoundTypeIds::MAGNESIUM_OXIDE));
		$this->map(Items::CHEMICAL_MAGNESIUM_SALTS(), self::chemical(CompoundTypeIds::MAGNESIUM_SALTS));
		$this->map(Items::CHEMICAL_MERCURIC_CHLORIDE(), self::chemical(CompoundTypeIds::MERCURIC_CHLORIDE));
		$this->map(Items::CHEMICAL_POLYETHYLENE(), self::chemical(CompoundTypeIds::POLYETHYLENE));
		$this->map(Items::CHEMICAL_POTASSIUM_CHLORIDE(), self::chemical(CompoundTypeIds::POTASSIUM_CHLORIDE));
		$this->map(Items::CHEMICAL_POTASSIUM_IODIDE(), self::chemical(CompoundTypeIds::POTASSIUM_IODIDE));
		$this->map(Items::CHEMICAL_RUBBISH(), self::chemical(CompoundTypeIds::RUBBISH));
		$this->map(Items::CHEMICAL_SALT(), self::chemical(CompoundTypeIds::SALT));
		$this->map(Items::CHEMICAL_SOAP(), self::chemical(CompoundTypeIds::SOAP));
		$this->map(Items::CHEMICAL_SODIUM_ACETATE(), self::chemical(CompoundTypeIds::SODIUM_ACETATE));
		$this->map(Items::CHEMICAL_SODIUM_FLUORIDE(), self::chemical(CompoundTypeIds::SODIUM_FLUORIDE));
		$this->map(Items::CHEMICAL_SODIUM_HYDRIDE(), self::chemical(CompoundTypeIds::SODIUM_HYDRIDE));
		$this->map(Items::CHEMICAL_SODIUM_HYDROXIDE(), self::chemical(CompoundTypeIds::SODIUM_HYDROXIDE));
		$this->map(Items::CHEMICAL_SODIUM_HYPOCHLORITE(), self::chemical(CompoundTypeIds::SODIUM_HYPOCHLORITE));
		$this->map(Items::CHEMICAL_SODIUM_OXIDE(), self::chemical(CompoundTypeIds::SODIUM_OXIDE));
		$this->map(Items::CHEMICAL_SUGAR(), self::chemical(CompoundTypeIds::SUGAR));
		$this->map(Items::CHEMICAL_SULPHATE(), self::chemical(CompoundTypeIds::SULPHATE));
		$this->map(Items::CHEMICAL_TUNGSTEN_CHLORIDE(), self::chemical(CompoundTypeIds::TUNGSTEN_CHLORIDE));
		$this->map(Items::CHEMICAL_WATER(), self::chemical(CompoundTypeIds::WATER));
		$this->map(Items::CHORUS_FRUIT(), self::id(Ids::CHORUS_FRUIT));
		$this->map(Items::CLAY(), self::id(Ids::CLAY_BALL));
		$this->map(Items::CLOCK(), self::id(Ids::CLOCK));
		$this->map(Items::CLOWNFISH(), self::id(Ids::TROPICAL_FISH));
		$this->map(Items::COAL(), self::id(Ids::COAL));
		$this->map(Items::COCOA_BEANS(), self::id(Ids::COCOA_BEANS));
		$this->map(Items::COMPASS(), self::id(Ids::COMPASS));
		$this->map(Items::COOKED_CHICKEN(), self::id(Ids::COOKED_CHICKEN));
		$this->map(Items::COOKED_FISH(), self::id(Ids::COOKED_COD));
		$this->map(Items::COOKED_MUTTON(), self::id(Ids::COOKED_MUTTON));
		$this->map(Items::COOKED_PORKCHOP(), self::id(Ids::COOKED_PORKCHOP));
		$this->map(Items::COOKED_RABBIT(), self::id(Ids::COOKED_RABBIT));
		$this->map(Items::COOKED_SALMON(), self::id(Ids::COOKED_SALMON));
		$this->map(Items::COOKIE(), self::id(Ids::COOKIE));
		$this->map(Items::CREEPER_HEAD(), self::skull(SkullType::CREEPER()));
		$this->map(Items::CYAN_BED(), self::bed(DyeColor::CYAN()));
		$this->map(Items::CYAN_DYE(), self::id(Ids::CYAN_DYE));
		$this->map(Items::DARK_OAK_BOAT(), self::id(Ids::DARK_OAK_BOAT));
		$this->map(Items::DARK_OAK_SIGN(), self::id(Ids::DARK_OAK_SIGN));
		$this->map(Items::DIAMOND(), self::id(Ids::DIAMOND));
		$this->map(Items::DIAMOND_AXE(), self::id(Ids::DIAMOND_AXE));
		$this->map(Items::DIAMOND_BOOTS(), self::id(Ids::DIAMOND_BOOTS));
		$this->map(Items::DIAMOND_CHESTPLATE(), self::id(Ids::DIAMOND_CHESTPLATE));
		$this->map(Items::DIAMOND_HELMET(), self::id(Ids::DIAMOND_HELMET));
		$this->map(Items::DIAMOND_HOE(), self::id(Ids::DIAMOND_HOE));
		$this->map(Items::DIAMOND_LEGGINGS(), self::id(Ids::DIAMOND_LEGGINGS));
		$this->map(Items::DIAMOND_PICKAXE(), self::id(Ids::DIAMOND_PICKAXE));
		$this->map(Items::DIAMOND_SHOVEL(), self::id(Ids::DIAMOND_SHOVEL));
		$this->map(Items::DIAMOND_SWORD(), self::id(Ids::DIAMOND_SWORD));
		$this->map(Items::DRAGON_BREATH(), self::id(Ids::DRAGON_BREATH));
		$this->map(Items::DRAGON_HEAD(), self::skull(SkullType::DRAGON()));
		$this->map(Items::DRIED_KELP(), self::id(Ids::DRIED_KELP));
		$this->map(Items::EGG(), self::id(Ids::EGG));
		$this->map(Items::EMERALD(), self::id(Ids::EMERALD));
		$this->map(Items::ENCHANTED_GOLDEN_APPLE(), self::id(Ids::ENCHANTED_GOLDEN_APPLE));
		$this->map(Items::ENDER_PEARL(), self::id(Ids::ENDER_PEARL));
		$this->map(Items::EXPERIENCE_BOTTLE(), self::id(Ids::EXPERIENCE_BOTTLE));
		$this->map(Items::FEATHER(), self::id(Ids::FEATHER));
		$this->map(Items::FERMENTED_SPIDER_EYE(), self::id(Ids::FERMENTED_SPIDER_EYE));
		$this->map(Items::FIRE_RESISTANCE_POTION(), self::potion(PotionType::FIRE_RESISTANCE()));
		$this->map(Items::FIRE_RESISTANCE_SPLASH_POTION(), self::splashPotion(PotionType::FIRE_RESISTANCE()));
		$this->map(Items::FISHING_ROD(), self::id(Ids::FISHING_ROD));
		$this->map(Items::FLINT(), self::id(Ids::FLINT));
		$this->map(Items::FLINT_AND_STEEL(), self::id(Ids::FLINT_AND_STEEL));
		$this->map(Items::GHAST_TEAR(), self::id(Ids::GHAST_TEAR));
		$this->map(Items::GLASS_BOTTLE(), self::id(Ids::GLASS_BOTTLE));
		$this->map(Items::GLISTERING_MELON(), self::id(Ids::GLISTERING_MELON_SLICE));
		$this->map(Items::GLOWSTONE_DUST(), self::id(Ids::GLOWSTONE_DUST));
		$this->map(Items::GOLDEN_APPLE(), self::id(Ids::GOLDEN_APPLE));
		$this->map(Items::GOLDEN_AXE(), self::id(Ids::GOLDEN_AXE));
		$this->map(Items::GOLDEN_BOOTS(), self::id(Ids::GOLDEN_BOOTS));
		$this->map(Items::GOLDEN_CARROT(), self::id(Ids::GOLDEN_CARROT));
		$this->map(Items::GOLDEN_CHESTPLATE(), self::id(Ids::GOLDEN_CHESTPLATE));
		$this->map(Items::GOLDEN_HELMET(), self::id(Ids::GOLDEN_HELMET));
		$this->map(Items::GOLDEN_HOE(), self::id(Ids::GOLDEN_HOE));
		$this->map(Items::GOLDEN_LEGGINGS(), self::id(Ids::GOLDEN_LEGGINGS));
		$this->map(Items::GOLDEN_PICKAXE(), self::id(Ids::GOLDEN_PICKAXE));
		$this->map(Items::GOLDEN_SHOVEL(), self::id(Ids::GOLDEN_SHOVEL));
		$this->map(Items::GOLDEN_SWORD(), self::id(Ids::GOLDEN_SWORD));
		$this->map(Items::GOLD_INGOT(), self::id(Ids::GOLD_INGOT));
		$this->map(Items::GOLD_NUGGET(), self::id(Ids::GOLD_NUGGET));
		$this->map(Items::GRAY_BED(), self::bed(DyeColor::GRAY()));
		$this->map(Items::GRAY_DYE(), self::id(Ids::GRAY_DYE));
		$this->map(Items::GREEN_BED(), self::bed(DyeColor::GREEN()));
		$this->map(Items::GREEN_DYE(), self::id(Ids::GREEN_DYE));
		$this->map(Items::GUNPOWDER(), self::id(Ids::GUNPOWDER));
		$this->map(Items::HARMING_POTION(), self::potion(PotionType::HARMING()));
		$this->map(Items::HARMING_SPLASH_POTION(), self::splashPotion(PotionType::HARMING()));
		$this->map(Items::HEALING_POTION(), self::potion(PotionType::HEALING()));
		$this->map(Items::HEALING_SPLASH_POTION(), self::splashPotion(PotionType::HEALING()));
		$this->map(Items::HEART_OF_THE_SEA(), self::id(Ids::HEART_OF_THE_SEA));
		$this->map(Items::INK_SAC(), self::id(Ids::INK_SAC));
		$this->map(Items::INVISIBILITY_POTION(), self::potion(PotionType::INVISIBILITY()));
		$this->map(Items::INVISIBILITY_SPLASH_POTION(), self::splashPotion(PotionType::INVISIBILITY()));
		$this->map(Items::IRON_AXE(), self::id(Ids::IRON_AXE));
		$this->map(Items::IRON_BOOTS(), self::id(Ids::IRON_BOOTS));
		$this->map(Items::IRON_CHESTPLATE(), self::id(Ids::IRON_CHESTPLATE));
		$this->map(Items::IRON_HELMET(), self::id(Ids::IRON_HELMET));
		$this->map(Items::IRON_HOE(), self::id(Ids::IRON_HOE));
		$this->map(Items::IRON_INGOT(), self::id(Ids::IRON_INGOT));
		$this->map(Items::IRON_LEGGINGS(), self::id(Ids::IRON_LEGGINGS));
		$this->map(Items::IRON_NUGGET(), self::id(Ids::IRON_NUGGET));
		$this->map(Items::IRON_PICKAXE(), self::id(Ids::IRON_PICKAXE));
		$this->map(Items::IRON_SHOVEL(), self::id(Ids::IRON_SHOVEL));
		$this->map(Items::IRON_SWORD(), self::id(Ids::IRON_SWORD));
		$this->map(Items::JUNGLE_BOAT(), self::id(Ids::JUNGLE_BOAT));
		$this->map(Items::JUNGLE_SIGN(), self::id(Ids::JUNGLE_SIGN));
		$this->map(Items::LAPIS_LAZULI(), self::id(Ids::LAPIS_LAZULI));
		$this->map(Items::LAVA_BUCKET(), self::id(Ids::LAVA_BUCKET));
		$this->map(Items::LEAPING_POTION(), self::potion(PotionType::LEAPING()));
		$this->map(Items::LEAPING_SPLASH_POTION(), self::splashPotion(PotionType::LEAPING()));
		$this->map(Items::LEATHER(), self::id(Ids::LEATHER));
		$this->map(Items::LEATHER_BOOTS(), self::id(Ids::LEATHER_BOOTS));
		$this->map(Items::LEATHER_CAP(), self::id(Ids::LEATHER_HELMET));
		$this->map(Items::LEATHER_PANTS(), self::id(Ids::LEATHER_LEGGINGS));
		$this->map(Items::LEATHER_TUNIC(), self::id(Ids::LEATHER_CHESTPLATE));
		$this->map(Items::LIGHT_BLUE_BED(), self::bed(DyeColor::LIGHT_BLUE()));
		$this->map(Items::LIGHT_BLUE_DYE(), self::id(Ids::LIGHT_BLUE_DYE));
		$this->map(Items::LIGHT_GRAY_BED(), self::bed(DyeColor::LIGHT_GRAY()));
		$this->map(Items::LIGHT_GRAY_DYE(), self::id(Ids::LIGHT_GRAY_DYE));
		$this->map(Items::LIME_BED(), self::bed(DyeColor::LIME()));
		$this->map(Items::LIME_DYE(), self::id(Ids::LIME_DYE));
		$this->map(Items::LONG_FIRE_RESISTANCE_POTION(), self::potion(PotionType::LONG_FIRE_RESISTANCE()));
		$this->map(Items::LONG_FIRE_RESISTANCE_SPLASH_POTION(), self::splashPotion(PotionType::LONG_FIRE_RESISTANCE()));
		$this->map(Items::LONG_INVISIBILITY_POTION(), self::potion(PotionType::LONG_INVISIBILITY()));
		$this->map(Items::LONG_INVISIBILITY_SPLASH_POTION(), self::splashPotion(PotionType::LONG_INVISIBILITY()));
		$this->map(Items::LONG_LEAPING_POTION(), self::potion(PotionType::LONG_LEAPING()));
		$this->map(Items::LONG_LEAPING_SPLASH_POTION(), self::splashPotion(PotionType::LONG_LEAPING()));
		$this->map(Items::LONG_MUNDANE_POTION(), self::potion(PotionType::LONG_MUNDANE()));
		$this->map(Items::LONG_MUNDANE_SPLASH_POTION(), self::splashPotion(PotionType::LONG_MUNDANE()));
		$this->map(Items::LONG_NIGHT_VISION_POTION(), self::potion(PotionType::LONG_NIGHT_VISION()));
		$this->map(Items::LONG_NIGHT_VISION_SPLASH_POTION(), self::splashPotion(PotionType::LONG_NIGHT_VISION()));
		$this->map(Items::LONG_POISON_POTION(), self::potion(PotionType::LONG_POISON()));
		$this->map(Items::LONG_POISON_SPLASH_POTION(), self::splashPotion(PotionType::LONG_POISON()));
		$this->map(Items::LONG_REGENERATION_POTION(), self::potion(PotionType::LONG_REGENERATION()));
		$this->map(Items::LONG_REGENERATION_SPLASH_POTION(), self::splashPotion(PotionType::LONG_REGENERATION()));
		$this->map(Items::LONG_SLOWNESS_POTION(), self::potion(PotionType::LONG_SLOWNESS()));
		$this->map(Items::LONG_SLOWNESS_SPLASH_POTION(), self::splashPotion(PotionType::LONG_SLOWNESS()));
		$this->map(Items::LONG_SLOW_FALLING_POTION(), self::potion(PotionType::LONG_SLOW_FALLING()));
		$this->map(Items::LONG_SLOW_FALLING_SPLASH_POTION(), self::splashPotion(PotionType::LONG_SLOW_FALLING()));
		$this->map(Items::LONG_STRENGTH_POTION(), self::potion(PotionType::LONG_STRENGTH()));
		$this->map(Items::LONG_STRENGTH_SPLASH_POTION(), self::splashPotion(PotionType::LONG_STRENGTH()));
		$this->map(Items::LONG_SWIFTNESS_POTION(), self::potion(PotionType::LONG_SWIFTNESS()));
		$this->map(Items::LONG_SWIFTNESS_SPLASH_POTION(), self::splashPotion(PotionType::LONG_SWIFTNESS()));
		$this->map(Items::LONG_TURTLE_MASTER_POTION(), self::potion(PotionType::LONG_TURTLE_MASTER()));
		$this->map(Items::LONG_TURTLE_MASTER_SPLASH_POTION(), self::splashPotion(PotionType::LONG_TURTLE_MASTER()));
		$this->map(Items::LONG_WATER_BREATHING_POTION(), self::potion(PotionType::LONG_WATER_BREATHING()));
		$this->map(Items::LONG_WATER_BREATHING_SPLASH_POTION(), self::splashPotion(PotionType::LONG_WATER_BREATHING()));
		$this->map(Items::LONG_WEAKNESS_POTION(), self::potion(PotionType::LONG_WEAKNESS()));
		$this->map(Items::LONG_WEAKNESS_SPLASH_POTION(), self::splashPotion(PotionType::LONG_WEAKNESS()));
		$this->map(Items::MAGENTA_BED(), self::bed(DyeColor::MAGENTA()));
		$this->map(Items::MAGENTA_DYE(), self::id(Ids::MAGENTA_DYE));
		$this->map(Items::MAGMA_CREAM(), self::id(Ids::MAGMA_CREAM));
		$this->map(Items::MELON(), self::id(Ids::MELON_SLICE));
		$this->map(Items::MELON_SEEDS(), self::id(Ids::MELON_SEEDS));
		$this->map(Items::MILK_BUCKET(), self::id(Ids::MILK_BUCKET));
		$this->map(Items::MINECART(), self::id(Ids::MINECART));
		$this->map(Items::MUNDANE_POTION(), self::potion(PotionType::MUNDANE()));
		$this->map(Items::MUNDANE_SPLASH_POTION(), self::splashPotion(PotionType::MUNDANE()));
		$this->map(Items::MUSHROOM_STEW(), self::id(Ids::MUSHROOM_STEW));
		$this->map(Items::NAUTILUS_SHELL(), self::id(Ids::NAUTILUS_SHELL));
		$this->map(Items::NETHER_BRICK(), self::id(Ids::NETHERBRICK));
		$this->map(Items::NETHER_QUARTZ(), self::id(Ids::QUARTZ));
		$this->map(Items::NETHER_STAR(), self::id(Ids::NETHER_STAR));
		$this->map(Items::NIGHT_VISION_POTION(), self::potion(PotionType::NIGHT_VISION()));
		$this->map(Items::NIGHT_VISION_SPLASH_POTION(), self::splashPotion(PotionType::NIGHT_VISION()));
		$this->map(Items::OAK_BOAT(), self::id(Ids::OAK_BOAT));
		$this->map(Items::OAK_SIGN(), self::id(Ids::OAK_SIGN));
		$this->map(Items::ORANGE_BED(), self::bed(DyeColor::ORANGE()));
		$this->map(Items::ORANGE_DYE(), self::id(Ids::ORANGE_DYE));
		$this->map(Items::PAINTING(), self::id(Ids::PAINTING));
		$this->map(Items::PAPER(), self::id(Ids::PAPER));
		$this->map(Items::PINK_BED(), self::bed(DyeColor::PINK()));
		$this->map(Items::PINK_DYE(), self::id(Ids::PINK_DYE));
		$this->map(Items::PLAYER_HEAD(), self::skull(SkullType::PLAYER()));
		$this->map(Items::POISONOUS_POTATO(), self::id(Ids::POISONOUS_POTATO));
		$this->map(Items::POISON_POTION(), self::potion(PotionType::POISON()));
		$this->map(Items::POISON_SPLASH_POTION(), self::splashPotion(PotionType::POISON()));
		$this->map(Items::POPPED_CHORUS_FRUIT(), self::id(Ids::POPPED_CHORUS_FRUIT));
		$this->map(Items::POTATO(), self::id(Ids::POTATO));
		$this->map(Items::PRISMARINE_CRYSTALS(), self::id(Ids::PRISMARINE_CRYSTALS));
		$this->map(Items::PRISMARINE_SHARD(), self::id(Ids::PRISMARINE_SHARD));
		$this->map(Items::PUFFERFISH(), self::id(Ids::PUFFERFISH));
		$this->map(Items::PUMPKIN_PIE(), self::id(Ids::PUMPKIN_PIE));
		$this->map(Items::PUMPKIN_SEEDS(), self::id(Ids::PUMPKIN_SEEDS));
		$this->map(Items::PURPLE_BED(), self::bed(DyeColor::PURPLE()));
		$this->map(Items::PURPLE_DYE(), self::id(Ids::PURPLE_DYE));
		$this->map(Items::RABBIT_FOOT(), self::id(Ids::RABBIT_FOOT));
		$this->map(Items::RABBIT_HIDE(), self::id(Ids::RABBIT_HIDE));
		$this->map(Items::RABBIT_STEW(), self::id(Ids::RABBIT_STEW));
		$this->map(Items::RAW_BEEF(), self::id(Ids::BEEF));
		$this->map(Items::RAW_CHICKEN(), self::id(Ids::CHICKEN));
		$this->map(Items::RAW_FISH(), self::id(Ids::COD));
		$this->map(Items::RAW_MUTTON(), self::id(Ids::MUTTON));
		$this->map(Items::RAW_PORKCHOP(), self::id(Ids::PORKCHOP));
		$this->map(Items::RAW_RABBIT(), self::id(Ids::RABBIT));
		$this->map(Items::RAW_SALMON(), self::id(Ids::SALMON));
		$this->map(Items::RECORD_11(), self::id(Ids::MUSIC_DISC_11));
		$this->map(Items::RECORD_13(), self::id(Ids::MUSIC_DISC_13));
		$this->map(Items::RECORD_BLOCKS(), self::id(Ids::MUSIC_DISC_BLOCKS));
		$this->map(Items::RECORD_CAT(), self::id(Ids::MUSIC_DISC_CAT));
		$this->map(Items::RECORD_CHIRP(), self::id(Ids::MUSIC_DISC_CHIRP));
		$this->map(Items::RECORD_FAR(), self::id(Ids::MUSIC_DISC_FAR));
		$this->map(Items::RECORD_MALL(), self::id(Ids::MUSIC_DISC_MALL));
		$this->map(Items::RECORD_MELLOHI(), self::id(Ids::MUSIC_DISC_MELLOHI));
		$this->map(Items::RECORD_STAL(), self::id(Ids::MUSIC_DISC_STAL));
		$this->map(Items::RECORD_STRAD(), self::id(Ids::MUSIC_DISC_STRAD));
		$this->map(Items::RECORD_WAIT(), self::id(Ids::MUSIC_DISC_WAIT));
		$this->map(Items::RECORD_WARD(), self::id(Ids::MUSIC_DISC_WARD));
		$this->map(Items::REDSTONE_DUST(), self::id(Ids::REDSTONE));
		$this->map(Items::RED_BED(), self::bed(DyeColor::RED()));
		$this->map(Items::RED_DYE(), self::id(Ids::RED_DYE));
		$this->map(Items::REGENERATION_POTION(), self::potion(PotionType::REGENERATION()));
		$this->map(Items::REGENERATION_SPLASH_POTION(), self::splashPotion(PotionType::REGENERATION()));
		$this->map(Items::ROTTEN_FLESH(), self::id(Ids::ROTTEN_FLESH));
		$this->map(Items::SCUTE(), self::id(Ids::SCUTE));
		$this->map(Items::SHEARS(), self::id(Ids::SHEARS));
		$this->map(Items::SHULKER_SHELL(), self::id(Ids::SHULKER_SHELL));
		$this->map(Items::SKELETON_SKULL(), self::skull(SkullType::SKELETON()));
		$this->map(Items::SLIMEBALL(), self::id(Ids::SLIME_BALL));
		$this->map(Items::SLOWNESS_POTION(), self::potion(PotionType::SLOWNESS()));
		$this->map(Items::SLOWNESS_SPLASH_POTION(), self::splashPotion(PotionType::SLOWNESS()));
		$this->map(Items::SLOW_FALLING_POTION(), self::potion(PotionType::SLOW_FALLING()));
		$this->map(Items::SLOW_FALLING_SPLASH_POTION(), self::splashPotion(PotionType::SLOW_FALLING()));
		$this->map(Items::SNOWBALL(), self::id(Ids::SNOWBALL));
		$this->map(Items::SPIDER_EYE(), self::id(Ids::SPIDER_EYE));
		$this->map(Items::SPRUCE_BOAT(), self::id(Ids::SPRUCE_BOAT));
		$this->map(Items::SPRUCE_SIGN(), self::id(Ids::SPRUCE_SIGN));
		$this->map(Items::SQUID_SPAWN_EGG(), self::id(Ids::SQUID_SPAWN_EGG));
		$this->map(Items::STEAK(), self::id(Ids::COOKED_BEEF));
		$this->map(Items::STICK(), self::id(Ids::STICK));
		$this->map(Items::STONE_AXE(), self::id(Ids::STONE_AXE));
		$this->map(Items::STONE_HOE(), self::id(Ids::STONE_HOE));
		$this->map(Items::STONE_PICKAXE(), self::id(Ids::STONE_PICKAXE));
		$this->map(Items::STONE_SHOVEL(), self::id(Ids::STONE_SHOVEL));
		$this->map(Items::STONE_SWORD(), self::id(Ids::STONE_SWORD));
		$this->map(Items::STRENGTH_POTION(), self::potion(PotionType::STRENGTH()));
		$this->map(Items::STRENGTH_SPLASH_POTION(), self::splashPotion(PotionType::STRENGTH()));
		$this->map(Items::STRING(), self::id(Ids::STRING));
		$this->map(Items::STRONG_HARMING_POTION(), self::potion(PotionType::STRONG_HARMING()));
		$this->map(Items::STRONG_HARMING_SPLASH_POTION(), self::splashPotion(PotionType::STRONG_HARMING()));
		$this->map(Items::STRONG_HEALING_POTION(), self::potion(PotionType::STRONG_HEALING()));
		$this->map(Items::STRONG_HEALING_SPLASH_POTION(), self::splashPotion(PotionType::STRONG_HEALING()));
		$this->map(Items::STRONG_LEAPING_POTION(), self::potion(PotionType::STRONG_LEAPING()));
		$this->map(Items::STRONG_LEAPING_SPLASH_POTION(), self::splashPotion(PotionType::STRONG_LEAPING()));
		$this->map(Items::STRONG_POISON_POTION(), self::potion(PotionType::STRONG_POISON()));
		$this->map(Items::STRONG_POISON_SPLASH_POTION(), self::splashPotion(PotionType::STRONG_POISON()));
		$this->map(Items::STRONG_REGENERATION_POTION(), self::potion(PotionType::STRONG_REGENERATION()));
		$this->map(Items::STRONG_REGENERATION_SPLASH_POTION(), self::splashPotion(PotionType::STRONG_REGENERATION()));
		$this->map(Items::STRONG_STRENGTH_POTION(), self::potion(PotionType::STRONG_STRENGTH()));
		$this->map(Items::STRONG_STRENGTH_SPLASH_POTION(), self::splashPotion(PotionType::STRONG_STRENGTH()));
		$this->map(Items::STRONG_SWIFTNESS_POTION(), self::potion(PotionType::STRONG_SWIFTNESS()));
		$this->map(Items::STRONG_SWIFTNESS_SPLASH_POTION(), self::splashPotion(PotionType::STRONG_SWIFTNESS()));
		$this->map(Items::STRONG_TURTLE_MASTER_POTION(), self::potion(PotionType::STRONG_TURTLE_MASTER()));
		$this->map(Items::STRONG_TURTLE_MASTER_SPLASH_POTION(), self::splashPotion(PotionType::STRONG_TURTLE_MASTER()));
		$this->map(Items::SUGAR(), self::id(Ids::SUGAR));
		$this->map(Items::SWEET_BERRIES(), self::id(Ids::SWEET_BERRIES));
		$this->map(Items::SWIFTNESS_POTION(), self::potion(PotionType::SWIFTNESS()));
		$this->map(Items::SWIFTNESS_SPLASH_POTION(), self::splashPotion(PotionType::SWIFTNESS()));
		$this->map(Items::THICK_POTION(), self::potion(PotionType::THICK()));
		$this->map(Items::THICK_SPLASH_POTION(), self::splashPotion(PotionType::THICK()));
		$this->map(Items::TOTEM(), self::id(Ids::TOTEM_OF_UNDYING));
		$this->map(Items::TURTLE_MASTER_POTION(), self::potion(PotionType::TURTLE_MASTER()));
		$this->map(Items::TURTLE_MASTER_SPLASH_POTION(), self::splashPotion(PotionType::TURTLE_MASTER()));
		$this->map(Items::VILLAGER_SPAWN_EGG(), self::id(Ids::VILLAGER_SPAWN_EGG));
		$this->map(Items::WATER_BREATHING_POTION(), self::potion(PotionType::WATER_BREATHING()));
		$this->map(Items::WATER_BREATHING_SPLASH_POTION(), self::splashPotion(PotionType::WATER_BREATHING()));
		$this->map(Items::WATER_BUCKET(), self::id(Ids::WATER_BUCKET));
		$this->map(Items::WATER_POTION(), self::potion(PotionType::WATER()));
		$this->map(Items::WATER_SPLASH_POTION(), self::splashPotion(PotionType::WATER()));
		$this->map(Items::WEAKNESS_POTION(), self::potion(PotionType::WEAKNESS()));
		$this->map(Items::WEAKNESS_SPLASH_POTION(), self::splashPotion(PotionType::WEAKNESS()));
		$this->map(Items::WHEAT(), self::id(Ids::WHEAT));
		$this->map(Items::WHEAT_SEEDS(), self::id(Ids::WHEAT_SEEDS));
		$this->map(Items::WHITE_BED(), self::bed(DyeColor::WHITE()));
		$this->map(Items::WHITE_DYE(), self::id(Ids::WHITE_DYE));
		$this->map(Items::WITHER_POTION(), self::potion(PotionType::WITHER()));
		$this->map(Items::WITHER_SKELETON_SKULL(), self::skull(SkullType::WITHER_SKELETON()));
		$this->map(Items::WITHER_SPLASH_POTION(), self::splashPotion(PotionType::WITHER()));
		$this->map(Items::WOODEN_AXE(), self::id(Ids::WOODEN_AXE));
		$this->map(Items::WOODEN_HOE(), self::id(Ids::WOODEN_HOE));
		$this->map(Items::WOODEN_PICKAXE(), self::id(Ids::WOODEN_PICKAXE));
		$this->map(Items::WOODEN_SHOVEL(), self::id(Ids::WOODEN_SHOVEL));
		$this->map(Items::WOODEN_SWORD(), self::id(Ids::WOODEN_SWORD));
		$this->map(Items::WRITABLE_BOOK(), self::id(Ids::WRITABLE_BOOK));
		$this->map(Items::WRITTEN_BOOK(), self::id(Ids::WRITTEN_BOOK));
		$this->map(Items::YELLOW_BED(), self::bed(DyeColor::YELLOW()));
		$this->map(Items::YELLOW_DYE(), self::id(Ids::YELLOW_DYE));
		$this->map(Items::ZOMBIE_HEAD(), self::skull(SkullType::ZOMBIE()));
		$this->map(Items::ZOMBIE_SPAWN_EGG(), self::id(Ids::ZOMBIE_SPAWN_EGG));
	}
}