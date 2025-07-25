<?php

namespace App\Livewire\Project\Shared;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use Livewire\Attributes\On;
use Livewire\Component;

class Terminal extends Component
{
    public bool $hasShell = true;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ApplicationStatusChanged" => 'closeTerminal',
        ];
    }

    public function closeTerminal()
    {
        $this->dispatch('reloadWindow');
    }

    private function checkShellAvailability(Server $server, string $container): bool
    {
        $escapedContainer = escapeshellarg($container);
        try {
            instant_remote_process([
                "docker exec {$escapedContainer} bash -c 'exit 0' 2>/dev/null || ".
                "docker exec {$escapedContainer} sh -c 'exit 0' 2>/dev/null",
            ], $server);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    #[On('send-terminal-command')]
    public function sendTerminalCommand($isContainer, $identifier, $serverUuid)
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($serverUuid)->firstOrFail();
        if (! $server->isTerminalEnabled() || $server->isForceDisabled()) {
            abort(403, 'Terminal access is disabled on this server.');
        }

        if ($isContainer) {
            // Validate container identifier format (alphanumeric, dashes, and underscores only)
            if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $identifier)) {
                throw new \InvalidArgumentException('Invalid container identifier format');
            }

            // Verify container exists and belongs to the user's team
            $status = getContainerStatus($server, $identifier);
            if ($status !== 'running') {
                return;
            }

            // Check shell availability
            $this->hasShell = $this->checkShellAvailability($server, $identifier);
            if (! $this->hasShell) {
                return;
            }

            // Escape the identifier for shell usage
            $escapedIdentifier = escapeshellarg($identifier);
            $shellCommand = 'PATH=$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin && '.
                            'if [ -f ~/.profile ]; then . ~/.profile; fi && '.
                            'if [ -n "$SHELL" ] && [ -x "$SHELL" ]; then exec $SHELL; else sh; fi';
            $command = SshMultiplexingHelper::generateSshCommand($server, "docker exec -it {$escapedIdentifier} sh -c '{$shellCommand}'");
        } else {
            $shellCommand = 'PATH=$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin && '.
                            'if [ -f ~/.profile ]; then . ~/.profile; fi && '.
                            'if [ -n "$SHELL" ] && [ -x "$SHELL" ]; then exec $SHELL; else sh; fi';
            $command = SshMultiplexingHelper::generateSshCommand($server, $shellCommand);
        }
        // ssh command is sent back to frontend then to websocket
        // this is done because the websocket connection is not available here
        // a better solution would be to remove websocket on NodeJS and work with something like
        // 1. Laravel Pusher/Echo connection (not possible without a sdk)
        // 2. Ratchet / Revolt / ReactPHP / Event Loop (possible but hard to implement and huge dependencies)
        // 3. Just found out about this https://github.com/sirn-se/websocket-php, perhaps it can be used
        // 4. Follow-up discussions here:
        //     - https://github.com/coollabsio/coolify/issues/2298
        //     - https://github.com/coollabsio/coolify/discussions/3362
        $this->dispatch('send-back-command', $command);
    }

    public function render()
    {
        return view('livewire.project.shared.terminal');
    }
}
