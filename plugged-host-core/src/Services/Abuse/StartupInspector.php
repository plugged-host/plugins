<?php

namespace PluggedHost\Core\Services\Abuse;

use App\Models\Server;

/**
 * A server's startup command and variable values, straight from the database: a GIT_ADDRESS that
 * clones xmrig, PY_PACKAGES that installs a proxy, a startup line that runs ./xray. No node call.
 */
final class StartupInspector
{
    /** Variables whose value is something the server runs or installs, so a bare tool name counts. */
    private const RUN_VARIABLES = ['PY_FILE', 'JS_FILE', 'MAIN_FILE', 'BOT_JS_FILE', 'BOT_PY_FILE', 'STARTUP_FILE', 'STARTUP_CMD', 'CMD_RUN', 'PY_PACKAGES', 'NODE_PACKAGES', 'NODE_ARGS', 'GIT_ADDRESS'];

    /**
     * @return array<int, Finding>
     */
    public function inspect(Server $server): array
    {
        $values = ['startup command' => (string) $server->startup];

        foreach ($server->variables as $variable) {
            $value = trim((string) $variable->server_value);

            if ($value !== '') {
                $values[(string) $variable->env_variable] = $value;
            }
        }

        $hits = [];

        foreach ($values as $where => $value) {
            foreach (Signatures::matchContent($value) as $hit) {
                $hits[] = $hit + ['where' => $where];
            }

            $runs = $where === 'startup command' || in_array($where, self::RUN_VARIABLES, true);

            foreach (preg_split('~[\s"\'=;,&|()<>]+~', $value) ?: [] as $token) {
                $name = basename(rtrim($token, '/'));

                foreach (Signatures::matchName($name, $runs) as $hit) {
                    $hits[] = $hit + ['where' => $where, 'match' => $token];
                }
            }
        }

        return Finding::fromHits('startup', $hits);
    }
}
