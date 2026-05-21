<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Entity\Account;
use App\Domain\Enum\AccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed',
    description: 'Seed database with sample accounts for testing',
)]
final class SeedAccountsCommand extends Command
{
    /**
     * Fixed ULIDs ensure README curl examples always work.
     * These are valid ULID format strings (26 chars, Crockford base32).
     */
    public const ALICE = '01HXFUND0000000000000AL1CE';
    public const BOB   = '01HXFUND000000000000000B0B';
    public const CAROL = '01HXFUND00000000000000CA01';
    public const DAVE  = '01HXFUND000000000000000DA5';
    public const EVE   = '01HXFUND0000000000000EV300';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $existing = $this->em->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM accounts')
            ->fetchOne();

        if ($existing > 0) {
            $io->warning('Database already has accounts. Skipping seed.');
            return Command::SUCCESS;
        }

        $accounts = [
            [self::ALICE, 'Alice Johnson', 1000000, 'USD', AccountStatus::Active],
            [self::BOB,   'Bob Smith',      500000, 'USD', AccountStatus::Active],
            [self::CAROL, 'Carol White',    250000, 'USD', AccountStatus::Active],
            [self::DAVE,  'Dave Brown',          0, 'USD', AccountStatus::Active],
            [self::EVE,   'Eve Davis',       100000, 'USD', AccountStatus::Frozen],
        ];

        foreach ($accounts as [$publicId, $name, $balance, $currency, $status]) {
            $account = new Account($name, $balance, $currency, $status, $publicId);
            $this->em->persist($account);
            $this->em->flush();
        }

        $io->success('Seeded 5 accounts:');
        $io->table(
            ['Owner', 'Public ID', 'Balance', 'Status'],
            array_map(fn(array $a) => [
                $a[1], $a[0],
                '$' . number_format($a[2] / 100, 2),
                $a[4]->value,
            ], $accounts)
        );

        return Command::SUCCESS;
    }
}
