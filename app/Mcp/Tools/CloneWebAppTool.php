<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('runcloud-clone-webapp')]
#[IsDestructive(false)]
class CloneWebAppTool extends RunCloudTool
{
    /**
     * Fields copied straight through to RunCloud when the caller supplies them.
     *
     * @var list<string>
     */
    protected const OPTIONAL_FIELDS = [
        'user', 'www', 'advancedSSL', 'autoSSL', 'portNumber', 'databaseClone', 'sourceDatabaseId',
        'databaseDestinationName', 'newDatabaseUsername', 'cloneNginxConfig', 'cloneRuncloudHub',
        'cloneBackup', 'cloneModsec', 'proxy',
    ];

    protected string $description = <<<'TEXT'
    Clone a web application to a destination server. RunCloud copies the application over SSH and
    creates a new web app under destinationName with its own domain; the source is left untouched.

    The destination can be the same server (pass its id as serverDestinationId) or a different
    RunCloud-managed server. For a cross-server clone RunCloud connects to the destination over SSH,
    so that server must be reachable on portNumber (default 22) and managed in the same account.

    What is copied:
    - The application files always.
    - The database only if databaseClone is true. That additionally needs sourceDatabaseId (from
      runcloud-databases on the source), databaseDestinationName (the new database's name), and
      newDatabaseUsername (the new database user). cloneRuncloudHub requires databaseClone as well.
    - The nginx config, backups, and ModSecurity rules only if cloneNginxConfig / cloneBackup /
      cloneModsec are set.

    This runs in the background. A success response means RunCloud accepted the clone and started
    it, not that the copy has finished — watch runcloud-webapps on the destination server for the new
    app to appear. Cloning copies data and creates resources on the destination, so it requires
    confirm=true. Before confirming, show the user the source app, the destination server, the new
    name and domain, and whether the database will be cloned.

    Discovery before calling: runcloud-webapps for the source webappId, runcloud-servers for the
    destination server, runcloud-system-users on the destination if you want a specific owner, and
    runcloud-databases on the source when cloning the database.
    TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'serverId' => $schema->integer()
                ->description('The source server hosting the web application to clone.')
                ->required(),
            'webappId' => $schema->integer()
                ->description('The source web application to clone.')
                ->required(),
            'serverDestinationId' => $schema->integer()
                ->description('The destination server id. Use the same value as serverId to clone onto the same server.')
                ->required(),
            'destinationName' => $schema->string()
                ->description('Name of the new web application on the destination server.')
                ->required(),
            'domainName' => $schema->string()
                ->description('Primary domain for the cloned application, without a scheme. For example "staging.example.com".')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true. Cloning copies data to the destination and creates resources there.')
                ->required(),
            'user' => $schema->integer()
                ->description('Destination system user id from runcloud-system-users. Defaults to the same user as the source app.'),
            'www' => $schema->boolean()
                ->description('Also serve the www. variant of the domain.'),
            'advancedSSL' => $schema->boolean()
                ->description('Enable advanced SSL on the clone. Defaults to true.'),
            'autoSSL' => $schema->boolean()
                ->description('Enable autoSSL on the clone. Defaults to false.'),
            'portNumber' => $schema->integer()
                ->description('SSH port of the destination server for a cross-server clone. Defaults to 22.'),
            'databaseClone' => $schema->boolean()
                ->description('Clone the database too. Requires sourceDatabaseId, databaseDestinationName, and newDatabaseUsername. Defaults to false.'),
            'sourceDatabaseId' => $schema->integer()
                ->description('Id of the source database to clone, from runcloud-databases. Required when databaseClone is true.'),
            'databaseDestinationName' => $schema->string()
                ->description('Name of the new database on the destination. Required when databaseClone is true.'),
            'newDatabaseUsername' => $schema->string()
                ->description('Name of the new database user on the destination. Required when databaseClone is true.'),
            'cloneNginxConfig' => $schema->boolean()
                ->description('Copy the nginx configuration. Defaults to false.'),
            'cloneRuncloudHub' => $schema->boolean()
                ->description('Copy the RunCloud Hub plugin. Requires databaseClone to be true. Defaults to false.'),
            'cloneBackup' => $schema->boolean()
                ->description('Copy backup configuration. Defaults to false.'),
            'cloneModsec' => $schema->boolean()
                ->description('Copy ModSecurity rules. Defaults to false.'),
            'proxy' => $schema->boolean()
                ->description('Enable Cloudflare proxy on the clone. Defaults to false.'),
        ];
    }

    protected function run(Request $request): Response
    {
        $request->validate([
            'serverId' => 'required|integer|min:1',
            'webappId' => 'required|integer|min:1',
            'serverDestinationId' => 'required|integer|min:1',
            'destinationName' => 'required|string|max:255',
            'domainName' => 'required|string|max:255',
            'confirm' => 'required|boolean',
            'user' => 'sometimes|integer|min:1',
            'www' => 'sometimes|boolean',
            'advancedSSL' => 'sometimes|boolean',
            'autoSSL' => 'sometimes|boolean',
            'portNumber' => 'sometimes|integer|min:1|max:65535',
            'databaseClone' => 'sometimes|boolean',
            'sourceDatabaseId' => 'required_if:databaseClone,true,1|integer|min:1',
            'databaseDestinationName' => 'required_if:databaseClone,true,1|string|max:255',
            'newDatabaseUsername' => 'required_if:databaseClone,true,1|string|max:255',
            'cloneNginxConfig' => 'sometimes|boolean',
            'cloneRuncloudHub' => 'sometimes|boolean',
            'cloneBackup' => 'sometimes|boolean',
            'cloneModsec' => 'sometimes|boolean',
            'proxy' => 'sometimes|boolean',
        ]);

        $serverId = $request->get('serverId');
        $webappId = $request->get('webappId');
        $destinationId = (int) $request->get('serverDestinationId');
        $destinationName = $request->get('destinationName');
        $cloningDatabase = $request->boolean('databaseClone');

        if ($request->boolean('cloneRuncloudHub') && ! $cloningDatabase) {
            return Response::error(
                'cloneRuncloudHub needs databaseClone to be true, because RunCloud Hub stores its state '.
                'in the database. Enable databaseClone or leave cloneRuncloudHub off.'
            );
        }

        $scope = $destinationId === (int) $serverId
            ? "onto the same server ({$serverId})"
            : "to server {$destinationId}";

        $withDatabase = $cloningDatabase
            ? " and its database as '{$request->get('databaseDestinationName')}'"
            : ' (files only, no database)';

        if ($request->get('confirm') !== true) {
            return Response::error(
                "Refused: cloning web app {$webappId} {$scope} as '{$destinationName}'{$withDatabase} ".
                'requires confirm=true. This copies the application to the destination over SSH and '.
                'creates new resources there. Show the user the source app, destination server, new '.
                'name and domain, and whether the database is included, then retry with confirm=true.'
            );
        }

        $payload = [
            'destinationName' => $destinationName,
            'domainName' => $request->get('domainName'),
            'serverDestinationId' => $destinationId,
        ];

        foreach (self::OPTIONAL_FIELDS as $field) {
            if ($request->get($field) !== null) {
                $payload[$field] = $request->get($field);
            }
        }

        $result = $this->client->post("servers/{$serverId}/webapps/{$webappId}/clone", $payload);

        return Response::json([
            'clone' => $result,
            'destinationServerId' => $destinationId,
            'databaseCloned' => $cloningDatabase,
            'notice' => 'Clone accepted and running in the background. This is not confirmation that the '.
                "copy has finished — check runcloud-webapps on server {$destinationId} for '{$destinationName}' ".
                'to appear, and allow time proportional to the size of the application and database.',
        ]);
    }
}
