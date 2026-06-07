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

namespace pocketmine\network\mcpe;

use pmmp\encoding\ByteBufferWriter;
use pocketmine\block\VanillaBlocks;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\types\ChunkPosition;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\serializer\ChunkSerializer;
use pocketmine\scheduler\AsyncTask;
use pocketmine\thread\NonThreadSafeValue;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\format\SubChunk;
use pocketmine\world\World;
use function chr;
use function count;
use function mt_rand;

class ChunkRequestTask extends AsyncTask{
	private const TLS_KEY_PROMISE = "promise";

	/**
	 * Percentage chance that a fully-hidden replaceable block is turned into a fake ore when anti-xray is enabled.
	 */
	private const ANTI_XRAY_ORE_CHANCE = 25;

	protected string $chunk;
	protected int $chunkX;
	protected int $chunkZ;
	/** @phpstan-var DimensionIds::* */
	private int $dimensionId;
	/** @phpstan-var NonThreadSafeValue<Compressor> */
	protected NonThreadSafeValue $compressor;
	private string $tiles;

	private bool $antiXray;
	private ?string $adjNorth;
	private ?string $adjSouth;
	private ?string $adjEast;
	private ?string $adjWest;

	/**
	 * @phpstan-param DimensionIds::* $dimensionId
	 *
	 * When $antiXray is true, the orthogonally-adjacent chunks are used so that blocks on the chunk border can be
	 * checked against their real neighbours. Any null adjacent chunk is treated as exposed terrain (left untouched).
	 */
	public function __construct(int $chunkX, int $chunkZ, int $dimensionId, Chunk $chunk, CompressBatchPromise $promise, Compressor $compressor, bool $antiXray = false, ?Chunk $north = null, ?Chunk $south = null, ?Chunk $east = null, ?Chunk $west = null){
		$this->compressor = new NonThreadSafeValue($compressor);

		$this->chunk = FastChunkSerializer::serializeTerrain($chunk);
		$this->chunkX = $chunkX;
		$this->chunkZ = $chunkZ;
		$this->dimensionId = $dimensionId;
		$this->tiles = ChunkSerializer::serializeTiles($chunk);

		$this->antiXray = $antiXray;
		$this->adjNorth = $north !== null ? FastChunkSerializer::serializeTerrain($north) : null;
		$this->adjSouth = $south !== null ? FastChunkSerializer::serializeTerrain($south) : null;
		$this->adjEast = $east !== null ? FastChunkSerializer::serializeTerrain($east) : null;
		$this->adjWest = $west !== null ? FastChunkSerializer::serializeTerrain($west) : null;

		$this->storeLocal(self::TLS_KEY_PROMISE, $promise);
	}

	public function onRun() : void{
		$chunk = FastChunkSerializer::deserializeTerrain($this->chunk);
		$dimensionId = $this->dimensionId;

		if($this->antiXray){
			$this->obfuscate($chunk);
		}

		$subCount = ChunkSerializer::getSubChunkCount($chunk, $dimensionId);
		$converter = TypeConverter::getInstance();
		$payload = ChunkSerializer::serializeFullChunk($chunk, $dimensionId, $converter->getBlockTranslator(), $this->tiles);

		$stream = new ByteBufferWriter();
		PacketBatch::encodePackets($stream, [LevelChunkPacket::create(new ChunkPosition($this->chunkX, $this->chunkZ), $dimensionId, $subCount, false, null, $payload)]);

		$compressor = $this->compressor->deserialize();
		$this->setResult(chr($compressor->getNetworkId()) . $compressor->compress($stream->getData()));
	}

	/**
	 * Anti-xray: replaces a portion of the fully-hidden stone/dirt/gravel blocks with random fake ores before the chunk
	 * is sent to the client. Only blocks whose six face neighbours are all replaceable (i.e. not exposed to air/caves)
	 * are touched, so a player without an xray resource pack never sees a difference. The real world chunk is not
	 * modified - only this serialized copy that is about to be sent.
	 */
	private function obfuscate(Chunk $chunk) : void{
		$replaceable = [
			VanillaBlocks::STONE()->getStateId() => true,
			VanillaBlocks::DIRT()->getStateId() => true,
			VanillaBlocks::GRAVEL()->getStateId() => true,
		];
		$ores = [
			VanillaBlocks::COAL_ORE()->getStateId(),
			VanillaBlocks::IRON_ORE()->getStateId(),
			VanillaBlocks::LAPIS_LAZULI_ORE()->getStateId(),
			VanillaBlocks::REDSTONE_ORE()->getStateId(),
			VanillaBlocks::GOLD_ORE()->getStateId(),
			VanillaBlocks::DIAMOND_ORE()->getStateId(),
			VanillaBlocks::EMERALD_ORE()->getStateId(),
		];
		$oreMaxIndex = count($ores) - 1;

		$chunks = [World::chunkHash($this->chunkX, $this->chunkZ) => $chunk];
		if($this->adjNorth !== null){
			$chunks[World::chunkHash($this->chunkX, $this->chunkZ - 1)] = FastChunkSerializer::deserializeTerrain($this->adjNorth);
		}
		if($this->adjSouth !== null){
			$chunks[World::chunkHash($this->chunkX, $this->chunkZ + 1)] = FastChunkSerializer::deserializeTerrain($this->adjSouth);
		}
		if($this->adjEast !== null){
			$chunks[World::chunkHash($this->chunkX + 1, $this->chunkZ)] = FastChunkSerializer::deserializeTerrain($this->adjEast);
		}
		if($this->adjWest !== null){
			$chunks[World::chunkHash($this->chunkX - 1, $this->chunkZ)] = FastChunkSerializer::deserializeTerrain($this->adjWest);
		}

		$baseX = $this->chunkX << Chunk::COORD_BIT_SIZE;
		$baseZ = $this->chunkZ << Chunk::COORD_BIT_SIZE;

		for($subY = Chunk::MIN_SUBCHUNK_INDEX; $subY <= Chunk::MAX_SUBCHUNK_INDEX; $subY++){
			$subChunk = $chunk->getSubChunk($subY);
			if($subChunk->isEmptyFast()){
				continue;
			}
			$subBaseY = $subY << SubChunk::COORD_BIT_SIZE;
			for($x = 0; $x < SubChunk::EDGE_LENGTH; $x++){
				for($z = 0; $z < SubChunk::EDGE_LENGTH; $z++){
					for($y = 0; $y < SubChunk::EDGE_LENGTH; $y++){
						if(!isset($replaceable[$subChunk->getBlockStateId($x, $y, $z)])){
							continue;
						}
						$worldX = $baseX + $x;
						$worldY = $subBaseY + $y;
						$worldZ = $baseZ + $z;
						if(
							!$this->isReplaceableAt($chunks, $replaceable, $worldX + 1, $worldY, $worldZ) ||
							!$this->isReplaceableAt($chunks, $replaceable, $worldX - 1, $worldY, $worldZ) ||
							!$this->isReplaceableAt($chunks, $replaceable, $worldX, $worldY + 1, $worldZ) ||
							!$this->isReplaceableAt($chunks, $replaceable, $worldX, $worldY - 1, $worldZ) ||
							!$this->isReplaceableAt($chunks, $replaceable, $worldX, $worldY, $worldZ + 1) ||
							!$this->isReplaceableAt($chunks, $replaceable, $worldX, $worldY, $worldZ - 1)
						){
							continue;
						}
						if(mt_rand(1, 100) > self::ANTI_XRAY_ORE_CHANCE){
							continue;
						}
						$subChunk->setBlockStateId($x, $y, $z, $ores[mt_rand(0, $oreMaxIndex)]);
					}
				}
			}
		}
	}

	/**
	 * @param array<int, Chunk> $chunks      chunks keyed by World::chunkHash
	 * @param array<int, true>  $replaceable replaceable block state ids
	 */
	private function isReplaceableAt(array $chunks, array $replaceable, int $worldX, int $worldY, int $worldZ) : bool{
		$subY = $worldY >> SubChunk::COORD_BIT_SIZE;
		if($subY < Chunk::MIN_SUBCHUNK_INDEX || $subY > Chunk::MAX_SUBCHUNK_INDEX){
			return false;
		}
		$chunk = $chunks[World::chunkHash($worldX >> Chunk::COORD_BIT_SIZE, $worldZ >> Chunk::COORD_BIT_SIZE)] ?? null;
		if($chunk === null){
			return false;
		}
		$stateId = $chunk->getSubChunk($subY)->getBlockStateId($worldX & SubChunk::COORD_MASK, $worldY & SubChunk::COORD_MASK, $worldZ & SubChunk::COORD_MASK);
		return isset($replaceable[$stateId]);
	}

	public function onCompletion() : void{
		/** @var CompressBatchPromise $promise */
		$promise = $this->fetchLocal(self::TLS_KEY_PROMISE);
		$promise->resolve($this->getResult());
	}
}
