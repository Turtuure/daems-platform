<?php

declare(strict_types=1);

namespace Daems\Application\Event\SubmitEventProposal;

use Daems\Domain\Event\EventProposal;
use Daems\Domain\Event\EventProposalId;
use Daems\Domain\Event\EventProposalRepositoryInterface;
use Daems\Domain\Locale\InvalidLocaleException;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\User\UserRepositoryInterface;

final class SubmitEventProposal
{
    public function __construct(
        private readonly EventProposalRepositoryInterface $proposals,
        private readonly UserRepositoryInterface $users,
    ) {
    }

    public function execute(SubmitEventProposalInput $input): SubmitEventProposalOutput
    {
        if (trim($input->title) === '' || trim($input->description) === '') {
            return new SubmitEventProposalOutput(false, null, 'title_and_description_required');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $input->eventDate)) {
            return new SubmitEventProposalOutput(false, null, 'invalid_event_date');
        }

        $sourceLocale = $input->sourceLocale;
        if ($sourceLocale === null) {
            $sourceLocale = SupportedLocale::UI_DEFAULT;
        } else {
            try {
                $sourceLocale = SupportedLocale::fromString($sourceLocale)->value();
            } catch (InvalidLocaleException) {
                return new SubmitEventProposalOutput(false, null, 'unsupported_source_locale');
            }
        }

        $user = $this->users->findById($input->acting->id->value());
        $authorName = $user !== null ? $user->name() : 'Unknown';
        $authorEmail = $user !== null ? $user->email() : '';

        $id = EventProposalId::generate();
        $proposal = new EventProposal(
            $id,
            $input->acting->activeTenant,
            $input->acting->id->value(),
            $authorName,
            $authorEmail,
            trim($input->title),
            $input->eventDate,
            $input->eventTime !== null && trim($input->eventTime) !== '' ? $input->eventTime : null,
            $input->location !== null && trim($input->location) !== '' ? $input->location : null,
            $input->isOnline,
            trim($input->description),
            $sourceLocale,
            'pending',
            date('Y-m-d H:i:s'),
        );

        $this->proposals->save($proposal);
        return new SubmitEventProposalOutput(true, $id->value());
    }
}
