<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Membership\SubmitMemberApplication\SubmitMemberApplication;
use Daems\Application\Membership\SubmitMemberApplication\SubmitMemberApplicationInput;
use Daems\Application\Membership\SubmitSupporterApplication\SubmitSupporterApplication;
use Daems\Application\Membership\SubmitSupporterApplication\SubmitSupporterApplicationInput;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class ApplicationController
{
    public function __construct(
        private readonly SubmitMemberApplication $submitMember,
        private readonly SubmitSupporterApplication $submitSupporter,
    ) {}

    public function member(Request $request): Response
    {
        $name        = trim((string) $request->input('name'));
        $email       = trim((string) $request->input('email'));
        $dob         = trim((string) $request->input('date_of_birth'));
        $country     = trim((string) $request->input('country')) ?: null;
        $motivation  = trim((string) $request->input('motivation'));
        $howHeard    = trim((string) $request->input('how_heard')) ?: null;

        if ($name === '' || $email === '' || $dob === '' || $motivation === '') {
            return Response::badRequest('Name, email, date of birth and motivation are required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::badRequest('Invalid email address.');
        }

        $output = $this->submitMember->execute(
            new SubmitMemberApplicationInput($name, $email, $dob, $country, $motivation, $howHeard),
        );

        return Response::json(['data' => ['id' => $output->id]], 201);
    }

    public function supporter(Request $request): Response
    {
        $orgName       = trim((string) $request->input('org_name'));
        $contactPerson = trim((string) $request->input('contact_person'));
        $regNo         = trim((string) $request->input('reg_no')) ?: null;
        $email         = trim((string) $request->input('email'));
        $country       = trim((string) $request->input('country')) ?: null;
        $motivation    = trim((string) $request->input('motivation'));
        $howHeard      = trim((string) $request->input('how_heard')) ?: null;

        if ($orgName === '' || $contactPerson === '' || $email === '' || $motivation === '') {
            return Response::badRequest('Organisation name, contact person, email and motivation are required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::badRequest('Invalid email address.');
        }

        $output = $this->submitSupporter->execute(
            new SubmitSupporterApplicationInput($orgName, $contactPerson, $regNo, $email, $country, $motivation, $howHeard),
        );

        return Response::json(['data' => ['id' => $output->id]], 201);
    }
}
