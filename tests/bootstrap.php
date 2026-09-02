<?php

/**
 * Bootstrap único das três suítes.
 *
 * O ponto crítico é a primeira linha executável: Config::findEnvFile() sobe até
 * oito diretórios procurando um .env. A NeoORM vive em
 * .../Projetos/NeoFramework/NeoORM, então essa busca alcança ~/Projetos e até
 * ~/. Um .env de qualquer outro projeto por ali apontaria a suíte para um banco
 * de verdade — e a suíte de integração apaga tabelas. A flag desliga a busca
 * antes de qualquer coisa ler configuração.
 */

declare(strict_types=1);

$_ENV['NEOORM_DISABLE_DOTENV'] = '1';
$_SERVER['NEOORM_DISABLE_DOTENV'] = '1';
putenv('NEOORM_DISABLE_DOTENV=1');

require __DIR__ . '/../vendor/autoload.php';
