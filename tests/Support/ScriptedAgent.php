<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Result;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\TextResult;

/**
 * An agent that replies with whatever it was told to, without a model.
 *
 * The authoring phase is judged on what it does with the reply — does the test
 * run, does it fail for the right reason, is it discarded when it passes — so
 * the reply is the only thing that needs to be scripted.
 */
final class ScriptedAgent implements AgentInterface
{
    /** @var list<string> */
    private array $replies;

    public function __construct(string ...$replies)
    {
        $this->replies = array_values($replies);
    }

    public function call(string|MessageBag|UserMessage $input, array $options = []): ExecutionInterface
    {
        $reply = array_shift($this->replies) ?? '';

        $factory = static function () use ($reply): \Generator {
            yield new Result(
                new TextResult($reply),
                static fn () => new MessageBag(),
            );
        };

        return new Execution($factory, false);
    }

    public function getName(): string
    {
        return 'scripted';
    }

    /** How many replies are left, so a test can assert it stopped early. */
    public function remaining(): int
    {
        return \count($this->replies);
    }
}
