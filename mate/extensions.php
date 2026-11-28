<?php

// This file is managed by Mate - use `discover` or `skills:*` commands
// over manual editing. Only changes to `mode` or `enabled` are kept,
// every other key is overwritten by Mate.

return [
    'survos/auth-bundle' => [
        'enabled' => true,
        'skills' => [
            'brevo-mailer' => [
                'enabled' => true,
                'mode' => 'managed',
                'state' => 'managed',
                'source' => 'vendor/survos/auth-bundle/skills/brevo-mailer',
                'source_hash' => 'sha256:24a8d1279d0595a5b249240f427011c7aa66fbea226c461f7d30eee72cf5e0b1',
                'hash' => 'sha256:cff83fd9a32a4b1a6586b6f715db732303bc7516ed86f47741acda5f48f3a16e',
                'targets' => [
                    '.agents/skills/mate-brevo-mailer',
                    '.claude/skills/mate-brevo-mailer',
                ],
            ],
            'oauth-login' => [
                'enabled' => true,
                'mode' => 'managed',
                'state' => 'managed',
                'source' => 'vendor/survos/auth-bundle/skills/oauth-login',
                'source_hash' => 'sha256:532b28111352017ddf7cba869c9716925645836e89c4c12c9bb5f92f80922427',
                'hash' => 'sha256:6b435b7736f9d95fc07802ce2f29c1465eef316b3f48c19c6ade1a349ca9179e',
                'targets' => [
                    '.agents/skills/mate-oauth-login',
                    '.claude/skills/mate-oauth-login',
                ],
            ],
        ],
    ],
    'survos/deployment-bundle' => [
        'enabled' => true,
        'skills' => [
            'dokku-deploy' => [
                'enabled' => true,
                'mode' => 'managed',
                'state' => 'managed',
                'source' => 'vendor/survos/deployment-bundle/skills/dokku-deploy',
                'source_hash' => 'sha256:39b1e632be676f24774701cb4ec882db1d8b1bc98187afaa9f1654c57543fd4e',
                'hash' => 'sha256:c85a60e23f50015dc1425df230fc45cbe383611c4bcd3ca17b699f8bb7360b46',
                'targets' => [
                    '.agents/skills/mate-dokku-deploy',
                    '.claude/skills/mate-dokku-deploy',
                ],
            ],
        ],
    ],
    'survos/jsonl-bundle' => [
        'enabled' => true,
        'skills' => [
            'survos-jsonl' => [
                'enabled' => true,
                'mode' => 'managed',
                'state' => 'managed',
                'source' => 'vendor/survos/jsonl-bundle/skills/survos-jsonl',
                'source_hash' => 'sha256:b132a09e220aa0b7de2ae9745c6f3053dd36f055dc8950875bf96923959e1abd',
                'hash' => 'sha256:40e6d8624e8eb99014b7b0df902c123e76a3b94bf5fb7d1a61129de9d6fefa00',
                'targets' => [
                    '.agents/skills/mate-survos-jsonl',
                    '.claude/skills/mate-survos-jsonl',
                ],
            ],
        ],
    ],
    'symfony/ai-mate' => [
        'enabled' => true,
        'skills' => [
            'php-environment-check' => [
                'enabled' => true,
                'mode' => 'managed',
                'state' => 'managed',
                'source' => 'vendor/symfony/ai-mate/skills/php-environment-check',
                'source_hash' => 'sha256:475400c87571d228335fb80f414c56a80110e182268df65f9f84bc9bfb2aa6f3',
                'hash' => 'sha256:56de92962de6233284c439de64b1cef66487c56c07e6c3357658139cf4d82527',
                'targets' => [
                    '.agents/skills/mate-php-environment-check',
                    '.claude/skills/mate-php-environment-check',
                ],
            ],
            'system-information' => [
                'enabled' => true,
                'mode' => 'managed',
                'state' => 'managed',
                'source' => 'vendor/symfony/ai-mate/skills/system-information',
                'source_hash' => 'sha256:7249544b603ecd416fae22cd92b465257619306f88a50dc8efd9653606e9e460',
                'hash' => 'sha256:fcbfe6ea831b35299f5ba646bf63dbd761e184e7ad196087a350a060f9915973',
                'targets' => [
                    '.agents/skills/mate-system-information',
                    '.claude/skills/mate-system-information',
                ],
            ],
        ],
    ],
    'symfony/ai-symfony-mate-extension' => [
        'enabled' => true,
        'skills' => [
            'symfony-dotenv-diagnostics' => [
                'enabled' => true,
                'mode' => 'managed',
                'state' => 'managed',
                'source' => 'vendor/symfony/ai-symfony-mate-extension/skills/symfony-dotenv-diagnostics',
                'source_hash' => 'sha256:527b1145e7386d15260509f88da26cb3158e17ae140f14c672ed299da1878ab0',
                'hash' => 'sha256:f4a3a9983496173dcdb8e27ed402a73e0f03d763060cbb668db1b993016d1f62',
                'targets' => [
                    '.agents/skills/mate-symfony-dotenv-diagnostics',
                    '.claude/skills/mate-symfony-dotenv-diagnostics',
                ],
            ],
            'symfony-profiler-debugging' => [
                'enabled' => true,
                'mode' => 'managed',
                'state' => 'managed',
                'source' => 'vendor/symfony/ai-symfony-mate-extension/skills/symfony-profiler-debugging',
                'source_hash' => 'sha256:bf384469e0e92af2b40ede7367090e4a41ff48f75a11f2ac5e64a2e4335068be',
                'hash' => 'sha256:adddef46d3cf8613d2d664f5f214e91572b6ae859406bd4d1d0196c11af1f7cb',
                'targets' => [
                    '.agents/skills/mate-symfony-profiler-debugging',
                    '.claude/skills/mate-symfony-profiler-debugging',
                ],
            ],
            'symfony-request-triage' => [
                'enabled' => true,
                'mode' => 'managed',
                'state' => 'managed',
                'source' => 'vendor/symfony/ai-symfony-mate-extension/skills/symfony-request-triage',
                'source_hash' => 'sha256:3b10b98407be5b6081423aeb8146b16eb81984264e50f2a5a38ac1706cc65581',
                'hash' => 'sha256:5d2c994451acdd9c07b18eba18833a15d7598b7f2cab3b7f8c5346ebc3928f4e',
                'targets' => [
                    '.agents/skills/mate-symfony-request-triage',
                    '.claude/skills/mate-symfony-request-triage',
                ],
            ],
            'symfony-service-inspection' => [
                'enabled' => true,
                'mode' => 'managed',
                'state' => 'managed',
                'source' => 'vendor/symfony/ai-symfony-mate-extension/skills/symfony-service-inspection',
                'source_hash' => 'sha256:6fa685f2b72129b96754ca1aff0e533f1c04fb5ff3a9ceddafab9059142bc0c8',
                'hash' => 'sha256:d4f0247d8c0355d714a1b6c8a738b2200bc802cf3c1f402a73b6866f164f14c1',
                'targets' => [
                    '.agents/skills/mate-symfony-service-inspection',
                    '.claude/skills/mate-symfony-service-inspection',
                ],
            ],
        ],
    ],
];
