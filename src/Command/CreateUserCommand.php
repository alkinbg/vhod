<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Person;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create', description: 'Create an invited Vhod user account.')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('first-name', InputArgument::REQUIRED)
            ->addArgument('last-name', InputArgument::REQUIRED)
            ->addOption('role', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Additional role', []);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim((string) $input->getArgument('email')));

        if (null !== $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email])) {
            $io->error('An account with this email already exists.');
            return Command::FAILURE;
        }

        if (!$input->isInteractive()) {
            $io->error('Interactive mode is required so the password is not exposed in shell history.');
            return Command::INVALID;
        }

        $passwordQuestion = new Question('Password: ');
        $passwordQuestion->setHidden(true);
        $passwordQuestion->setHiddenFallback(false);

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $plainPassword = $helper->ask($input, $output, $passwordQuestion);

        if (!is_string($plainPassword) || mb_strlen($plainPassword) < 12) {
            $io->error('Password must be at least 12 characters.');
            return Command::INVALID;
        }

        $person = new Person(
            (string) $input->getArgument('first-name'),
            (string) $input->getArgument('last-name'),
            email: $email,
        );

        $user = new User($person, $email, 'pending');
        $user->changePasswordHash($this->passwordHasher->hashPassword($user, $plainPassword));

        /** @var list<string> $roles */
        $roles = $input->getOption('role');

        try {
            $user->setRoles($roles);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());
            return Command::INVALID;
        }

        $this->entityManager->persist($person);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Created account for %s.', $email));
        return Command::SUCCESS;
    }
}
