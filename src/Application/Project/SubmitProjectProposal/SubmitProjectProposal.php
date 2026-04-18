<?php

declare(strict_types=1);

namespace Daems\Application\Project\SubmitProjectProposal;

use Daems\Domain\Project\ProjectProposal;
use Daems\Domain\Project\ProjectProposalId;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;

final class SubmitProjectProposal
{
    public function __construct(
        private readonly ProjectProposalRepositoryInterface $proposals,
    ) {}

    public function execute(SubmitProjectProposalInput $input): SubmitProjectProposalOutput
    {
        if (trim($input->title) === '' || trim($input->description) === '') {
            return new SubmitProjectProposalOutput(false, 'Title and description are required.');
        }

        $proposal = new ProjectProposal(
            ProjectProposalId::generate(),
            $input->userId,
            $input->authorName,
            $input->authorEmail,
            trim($input->title),
            trim($input->category),
            trim($input->summary),
            trim($input->description),
            'pending',
            date('Y-m-d H:i:s'),
        );

        $this->proposals->save($proposal);
        return new SubmitProjectProposalOutput(true);
    }
}
