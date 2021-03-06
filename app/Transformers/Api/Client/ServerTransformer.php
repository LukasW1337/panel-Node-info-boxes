<?php

namespace Pterodactyl\Transformers\Api\Client;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;

class ServerTransformer extends BaseClientTransformer
{
    /**
     * @var array
     */
    protected $availableIncludes = ['egg'];

    /**
     * @return string
     */
    public function getResourceName(): string
    {
        return Server::RESOURCE_NAME;
    }

    /**
     * Transform a server model into a representation that can be returned
     * to a client.
     *
     * @param \Pterodactyl\Models\Server $server
     * @return array
     */
    public function transform(Server $server): array
    {
        return [
            'server_owner' => $this->getKey()->user_id === $server->owner_id,
            'identifier' => $server->uuidShort,
            'uuid' => $server->uuid,
            'name' => $server->name,
            'node' => $server->node->name,
            'description' => $server->description,
            'allocation' => [
                'ip' => $server->allocation->alias,
                'port' => $server->allocation->port,
            ],
            'limits' => [
                'memory' => $server->memory,
                'swap' => $server->swap,
                'disk' => $server->disk,
                'io' => $server->io,
                'cpu' => $server->cpu,
            ],
            'feature_limits' => [
                'databases' => $server->database_limit,
                'allocations' => $server->allocation_limit,
            ],
        ];
    }

    /**
     * Returns the egg associated with this server.
     *
     * @param \Pterodactyl\Models\Server $server
     * @return \League\Fractal\Resource\Item
     * @throws \Pterodactyl\Exceptions\Transformer\InvalidTransformerLevelException
     */
    public function includeEgg(Server $server)
    {
        return $this->item($server->egg, $this->makeTransformer(EggTransformer::class), Egg::RESOURCE_NAME);
    }
}
