<?php

namespace Pterodactyl\Services\Eggs\Sharing;

use Illuminate\Http\UploadedFile;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Contracts\Repository\EggRepositoryInterface;
use Pterodactyl\Exceptions\Service\Egg\BadJsonFormatException;
use Pterodactyl\Exceptions\Service\InvalidFileUploadException;
use Pterodactyl\Contracts\Repository\EggVariableRepositoryInterface;

class EggUpdateImporterService
{
    /**
     * @var \Illuminate\Database\ConnectionInterface
     */
    protected $connection;

    /**
     * @var \Pterodactyl\Contracts\Repository\EggRepositoryInterface
     */
    protected $repository;

    /**
     * @var \Pterodactyl\Contracts\Repository\EggVariableRepositoryInterface
     */
    protected $variableRepository;

    /**
     * EggUpdateImporterService constructor.
     *
     * @param \Illuminate\Database\ConnectionInterface $connection
     * @param \Pterodactyl\Contracts\Repository\EggRepositoryInterface $repository
     * @param \Pterodactyl\Contracts\Repository\EggVariableRepositoryInterface $variableRepository
     */
    public function __construct(
        ConnectionInterface $connection,
        EggRepositoryInterface $repository,
        EggVariableRepositoryInterface $variableRepository
    ) {
        $this->connection = $connection;
        $this->repository = $repository;
        $this->variableRepository = $variableRepository;
    }

    /**
     * Update an existing Egg using an uploaded JSON file.
     *
     * @param int $egg
     * @param \Illuminate\Http\UploadedFile $file
     *
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     * @throws \Pterodactyl\Exceptions\Repository\RecordNotFoundException
     * @throws \Pterodactyl\Exceptions\Service\Egg\BadJsonFormatException
     * @throws \Pterodactyl\Exceptions\Service\InvalidFileUploadException
     */
    public function handle(int $egg, UploadedFile $file)
    {
        if ($file->getError() !== UPLOAD_ERR_OK || ! $file->isFile()) {
            throw new InvalidFileUploadException(trans('exceptions.nest.importer.file_error'));
        }

        $parsed = json_decode($file->openFile()->fread($file->getSize()));
        if (json_last_error() !== 0) {
            throw new BadJsonFormatException(trans('exceptions.nest.importer.json_error', [
                'error' => json_last_error_msg(),
            ]));
        }

        if (object_get($parsed, 'meta.version') !== 'PTDL_v1') {
            throw new InvalidFileUploadException(trans('exceptions.nest.importer.invalid_json_provided'));
        }

        $this->connection->beginTransaction();
        $this->repository->update($egg, [
            'author' => object_get($parsed, 'author'),
            'name' => object_get($parsed, 'name'),
            'description' => object_get($parsed, 'description'),
            'docker_image' => object_get($parsed, 'image'),
            'config_files' => object_get($parsed, 'config.files'),
            'config_startup' => object_get($parsed, 'config.startup'),
            'config_logs' => object_get($parsed, 'config.logs'),
            'config_stop' => object_get($parsed, 'config.stop'),
            'startup' => object_get($parsed, 'startup'),
            'script_install' => object_get($parsed, 'scripts.installation.script'),
            'script_entry' => object_get($parsed, 'scripts.installation.entrypoint'),
            'script_container' => object_get($parsed, 'scripts.installation.container'),
        ], true, true);

        // Update Existing Variables
        collect($parsed->variables)->each(function ($variable) use ($egg) {
            $this->variableRepository->withoutFreshModel()->updateOrCreate([
                'egg_id' => $egg,
                'env_variable' => $variable->env_variable,
            ], collect($variable)->except(['egg_id', 'env_variable'])->toArray());
        });

        $imported = collect($parsed->variables)->pluck('env_variable')->toArray();
        $existing = $this->variableRepository->setColumns(['id', 'env_variable'])->findWhere([['egg_id', '=', $egg]]);

        // Delete variables not present in the import.
        collect($existing)->each(function ($variable) use ($egg, $imported) {
            if (! in_array($variable->env_variable, $imported)) {
                $this->variableRepository->deleteWhere([
                    ['egg_id', '=', $egg],
                    ['env_variable', '=', $variable->env_variable],
                ]);
            }
        });

        $this->connection->commit();
    }
}
