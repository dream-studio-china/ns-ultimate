<?php

declare(strict_types=1);

namespace App\Wechat\Service;

use App\Identity\Entity\User;

interface WechatAuthServiceInterface
{
    /**
     * Mini Program login: js_code → User
     */
    public function authenticateFromMiniApp(string $jsCode): User;

    /**
     * Official Account login: oauth code → User
     */
    public function authenticateFromOfficialAccount(string $code): User;

    /**
     * Bind phone number to authenticated user
     */
    public function bindPhone(User $user, string $code): void;
}
