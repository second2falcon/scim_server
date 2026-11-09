<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 SCIM Server contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ScimServer\Command;

use OCA\ScimServer\Db\Store;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** occ scim:token:create <label>: prints a new bearer token (for automation and tests). */
class CreateToken extends Command {
	public function __construct(private Store $store) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('scim:token:create')
			->setDescription('Create a SCIM bearer token and print it once')
			->addArgument('label', InputArgument::OPTIONAL, 'Label of the token', 'cli');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln($this->store->createToken((string)$input->getArgument('label'))['token']);
		return 0;
	}
}
