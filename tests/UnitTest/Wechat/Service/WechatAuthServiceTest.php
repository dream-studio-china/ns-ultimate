<?php

declare(strict_types=1);

namespace App\Tests\UnitTest\Wechat\Service;

use App\Identity\Entity\User;
use App\Wechat\Entity\WechatUser;
use App\Wechat\Repository\WechatUserRepository;
use App\Wechat\Service\WechatAuthService;
use App\Wechat\Service\WechatServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use EasyWeChat\MiniApp\Application as MiniApp;
use EasyWeChat\OfficialAccount\Application as OfficialAccount;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class WechatAuthServiceTest extends TestCase
{
    private WechatServiceInterface $wechatService;
    private WechatUserRepository $wechatUserRepo;
    private EntityManagerInterface $em;
    private WechatAuthService $authService;

    protected function setUp(): void
    {
        $this->wechatService = $this->createMock(WechatServiceInterface::class);
        $this->wechatService->method('getMiniApp')->willReturn(new MiniApp(['app_id' => 'wx-mini-test', 'secret' => 'test']));
        $this->wechatService->method('getOfficialAccount')->willReturn(new OfficialAccount(['app_id' => 'wx-official-test', 'secret' => 'test']));
        $this->wechatUserRepo = $this->createMock(WechatUserRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->authService = new WechatAuthService(
            $this->wechatService,
            $this->wechatUserRepo,
            $this->em,
        );
    }

    public function testAuthenticateFromMiniAppExistingUser(): void
    {
        $existingUser = new User();
        $existingWechatUser = $this->createMock(WechatUser::class);
        $existingWechatUser->method('getUser')->willReturn($existingUser);

        $this->wechatService->method('code2Session')
            ->with('valid_js_code')
            ->willReturn(['openid' => 'o_test', 'unionid' => 'u_test', 'session_key' => 'sk_test']);

        $this->wechatUserRepo->method('findByAppIdAndOpenid')
            ->with('wx-mini-test', 'o_test')
            ->willReturn($existingWechatUser);

        $result = $this->authService->authenticateFromMiniApp('valid_js_code');

        self::assertSame($existingUser, $result);
    }

    public function testAuthenticateFromMiniAppNewUser(): void
    {
        $this->wechatService->method('code2Session')
            ->with('new_js_code')
            ->willReturn(['openid' => 'o_new', 'unionid' => null, 'session_key' => 'sk_new']);

        $this->wechatUserRepo->method('findByAppIdAndOpenid')
            ->with('wx-mini-test', 'o_new')
            ->willReturn(null);

        $this->em->expects(self::exactly(2))->method('persist');
        $this->em->expects(self::once())->method('flush');

        $result = $this->authService->authenticateFromMiniApp('new_js_code');

        self::assertInstanceOf(User::class, $result);
        $identifier = substr(hash('sha256', "wx-mini-test\0o_new"), 0, 24);
        self::assertSame('wx_'.$identifier, $result->getUsername());
        self::assertSame('wx_'.$identifier.'@wechat.local', $result->getEmail());
    }

    public function testSameOpenidFromDifferentAppsCreatesDistinctUsers(): void
    {
        $this->wechatService->method('code2Session')->willReturnMap([
            ['app_code', ['openid' => 'same_openid', 'unionid' => null, 'session_key' => 'session']],
        ]);
        $this->wechatUserRepo->method('findByAppIdAndOpenid')->willReturn(null);
        $users = [];
        $this->em->expects(self::exactly(4))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$users): void {
                if ($entity instanceof User) {
                    $users[] = $entity;
                }
            });
        $this->em->expects(self::exactly(2))->method('flush');

        $this->authService->authenticateFromMiniApp('app_code');
        $otherAppService = $this->createMock(WechatServiceInterface::class);
        $otherAppService->method('getMiniApp')->willReturn(new MiniApp(['app_id' => 'wx-mini-other', 'secret' => 'test']));
        $otherAppService->method('code2Session')->willReturn([
            'openid' => 'same_openid',
            'unionid' => null,
            'session_key' => 'session',
        ]);
        $otherAuthService = new WechatAuthService($otherAppService, $this->wechatUserRepo, $this->em);
        $otherAuthService->authenticateFromMiniApp('app_code');

        self::assertCount(2, $users);
        self::assertNotSame($users[0]->getUsername(), $users[1]->getUsername());
        self::assertNotSame($users[0]->getEmail(), $users[1]->getEmail());
    }

    public function testAuthenticateFromOfficialAccountExistingUser(): void
    {
        $existingUser = new User();
        $existingWechatUser = $this->createMock(WechatUser::class);
        $existingWechatUser->method('getUser')->willReturn($existingUser);

        $this->wechatService->method('getOAuthUser')
            ->with('oauth_code')
            ->willReturn([
                'openid' => 'o_oa',
                'nickname' => 'OAuthUser',
                'avatar' => 'https://img/1.jpg',
                'sex' => 1,
                'province' => 'GD',
                'city' => 'SZ',
                'country' => 'CN',
                'unionid' => null,
            ]);

        $this->wechatUserRepo->method('findByAppIdAndOpenid')
            ->with('wx-official-test', 'o_oa')
            ->willReturn($existingWechatUser);

        $result = $this->authService->authenticateFromOfficialAccount('oauth_code');

        self::assertSame($existingUser, $result);
    }

    public function testAuthenticateFromOfficialAccountNewUser(): void
    {
        $this->wechatService->method('getOAuthUser')
            ->with('oauth_code_new')
            ->willReturn([
                'openid' => 'o_new_oa',
                'nickname' => 'NewOA',
                'avatar' => 'https://img/2.jpg',
                'sex' => 2,
                'province' => 'BJ',
                'city' => 'BJ',
                'country' => 'CN',
                'unionid' => 'u_union',
            ]);

        $this->wechatUserRepo->method('findByAppIdAndOpenid')
            ->with('wx-official-test', 'o_new_oa')
            ->willReturn(null);

        $this->em->expects(self::exactly(2))->method('persist');
        $this->em->expects(self::once())->method('flush');

        $result = $this->authService->authenticateFromOfficialAccount('oauth_code_new');

        self::assertInstanceOf(User::class, $result);
    }

    public function testBindPhoneUpdatesUserPhone(): void
    {
        $user = new User();
        $user->setEmail('test@test.com');
        self::assertFalse($user->isPhoneVerified());

        $this->wechatService->method('getPhoneNumber')
            ->with('phone_code')
            ->willReturn(['phoneNumber' => '+8613800138000']);

        $this->em->expects(self::once())->method('flush');

        $this->authService->bindPhone($user, 'phone_code');

        self::assertSame('+8613800138000', $user->getPhone());
        self::assertTrue($user->isPhoneVerified());
    }

    public function testBindPhoneThrowsOnWechatError(): void
    {
        $user = new User();

        $this->wechatService->method('getPhoneNumber')
            ->with('bad_code')
            ->willThrowException(new \RuntimeException('WeChat error'));

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('WeChat error');

        $this->authService->bindPhone($user, 'bad_code');
    }
}
