<?php

declare(strict_types=1);

namespace Daems\Application\Project\SubmitProjectProposal;

use Daems\Domain\Locale\InvalidLocaleException;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Project\ProjectProposal;
use Daems\Domain\Project\ProjectProposalId;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;
use Daems\Domain\User\UserRepositoryInterface;

final class SubmitProjectProposal
{
    public function __construct(
        private readonly ProjectProposalRepositoryInterface $proposals,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function execute(SubmitProjectProposalInput $input): SubmitProjectProposalOutput
    {
        if (trim($input->title) === '' || trim($input->description) === '') {
            return new SubmitProjectProposalOutput(false, 'Title and description are required.');
        }

        $user = $this->users->findById($input->acting->id->value());
        $authorName = $user !== null ? $user->name() : 'Unknown';
        $authorEmail = $user !== null ? $user->email() : '';

        $sourceLocale = $input->sourceLocale;
        if ($sourceLocale === null) {
            $sourceLocale = SupportedLocale::UI_DEFAULT;
        } else {
            try {
                $sourceLocale = SupportedLocale::fromString($sourceLocale)->value();
            } catch (InvalidLocaleException) {
                return new SubmitProjectProposalOutput(false, 'Unsupported source_locale.');
            }
        }

        $proposal = new ProjectProposal(
            ProjectProposalId::generate(),
            $input->acting->activeTenant,
            $input->acting->id->value(),
            $authorName,
            $authorEmail,
            trim($input->title),
            trim($input->category),
            trim($input->summary),
            trim($input->description),
            'pending',
            date('Y-m-d H:i:s'),
            null,
            null,
            null,
            $sourceLocale,
        );

        $this->proposals->save($proposal);
        return new SubmitProjectProposalOutput(true);
    }
}
