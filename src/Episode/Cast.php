<?php

declare(strict_types=1);

namespace App\Episode;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The show and its hosts, from the "kpa.episodes" parameter (config/packages/kpa_episodes.yaml).
 */
final readonly class Cast
{
    public string $title;
    /** @var array<string, Host> */
    public array $hosts;

    /**
     * @param array{title: string, hosts: array<string, array{name: string, persona: string, voices: array<string, string>}>} $config
     */
    public function __construct(#[Autowire(param: 'kpa.episodes')] array $config)
    {
        $this->title = $config['title'];
        $hosts = [];
        foreach ($config['hosts'] as $id => $host) {
            $hosts[$id] = new Host($id, $host['name'], $host['persona'], $host['voices']);
        }
        $this->hosts = $hosts;
    }
}
