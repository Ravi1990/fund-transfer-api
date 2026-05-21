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
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Check if already seeded
        $existing = $this->em->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM accounts')
            ->fetchOne();

        if ($existing > 0) {
            $io->warning('Database already has accounts. Skipping seed.');
            $io->note('Run: docker compose exec mysql mysql -u app -papp_pass fund_transfer -e "TRUNCATE accounts;" to reset.');
            return Command::SUCCESS;
        }

        $accounts = [
            ['Alice Johnson', 1000000, 'USD', AccountStatus::Active],   // $10,000.00
            ['Bob Smith',      500000, 'USD', AccountStatus::Active],   // $5,000.00
            ['Carol White',    250000, 'USD', AccountStatus::Active],   // $2,500.00
            ['Dave Brown',         0, 'USD', AccountStatus::Active],   // $0.00 (for insufficient funds test)
            ['Eve Davis',     100000, 'USD', AccountStatus::Frozen],   // frozen account test
        ];

        $created = [];
        foreach ($accounts as [$name, $balance, $currency, $status]) {
            $account = new Account($name, $balance, $currency, $status);
            $this->em->persist($account);
            $this->em->flush();
            $created[] = $account;
        }

        $io->success('Seeded ' . count($created) . ' accounts:');

        $io->table(
            ['Owner', 'Public ID', 'Balance', 'Currency', 'Status'],
            array_map(fn(Account $a) => [
                $a->getOwnerName(),
                $a->getPublicId(),
                '$' . number_format($a->getBalanceCents() / 100, 2),
                $a->getCurrency(),
                $a->getStatus()->value,
            ], $created)
        );

        $io->section('Quick test commands:');

        $alice = $created[0]->getPublicId();
        $bob   = $created[1]->getPublicId();
        $dave  = $created[3]->getPublicId();
        $eve   = $created[4]->getPublicId();

        $io->writeln("# Happy path transfer (Alice → Bob):");
        $io->writeln("curl -s -X POST http://localhost:8080/api/v1/transfers \\");
        $io->writeln("  -H 'Content-Type: application/json' \\");
        $io->writeln("  -d '{");
        $io->writeln('    "idempotency_key": "test-001",');
        $io->writeln('    "from_account_id": "' . $alice . '",');
        $io->writeln('    "to_account_id":   "' . $bob . '",');
        $io->writeln('    "amount":          "100.50",');
        $io->writeln('    "currency":        "USD"');
        $io->writeln("  }' | python3 -m json.tool");
        $io->writeln('');
        $io->writeln("# Insufficient funds (Dave has \$0):");
        $io->writeln("curl -s -X POST http://localhost:8080/api/v1/transfers \\");
        $io->writeln("  -H 'Content-Type: application/json' \\");
        $io->writeln("  -d '{");
        $io->writeln('    "idempotency_key": "test-002",');
        $io->writeln('    "from_account_id": "' . $dave . '",');
        $io->writeln('    "to_account_id":   "' . $alice . '",');
        $io->writeln('    "amount":          "10.00",');
        $io->writeln('    "currency":        "USD"');
        $io->writeln("  }' | python3 -m json.tool");
        $io->writeln('');
        $io->writeln("# Frozen account (Eve is frozen):");
        $io->writeln("curl -s -X POST http://localhost:8080/api/v1/transfers \\");
        $io->writeln("  -H 'Content-Type: application/json' \\");
        $io->writeln("  -d '{");
        $io->writeln('    "idempotency_key": "test-003",');
        $io->writeln('    "from_account_id": "' . $eve . '",');
        $io->writeln('    "to_account_id":   "' . $alice . '",');
        $io->writeln('    "amount":          "10.00",');
        $io->writeln('    "currency":        "USD"');
        $io->writeln("  }' | python3 -m json.tool");

        return Command::SUCCESS;
    }
}
