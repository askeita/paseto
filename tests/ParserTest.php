<?php
declare(strict_types=1);
namespace ParagonIE\Paseto\Tests;

use ParagonIE\ConstantTime\Hex;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Exception\PasetoException;
use ParagonIE\Paseto\JsonToken;
use ParagonIE\Paseto\Keys\{
    AsymmetricSecretKey,
    SymmetricKey
};
use ParagonIE\Paseto\Keys\Version2\{
    AsymmetricSecretKey as V2AsymmetricSecretKey,
    SymmetricKey as V2SymmetricKey
};
use ParagonIE\Paseto\Parser;
use ParagonIE\Paseto\Purpose;
use ParagonIE\Paseto\Protocol\Version2;
use ParagonIE\Paseto\ProtocolCollection;
use ParagonIE\Paseto\Rules\NotExpired;
use PHPUnit\Framework\TestCase;

/**
 * Class ParserTest
 * @package ParagonIE\Paseto\Tests
 */
class ParserTest extends TestCase
{
    /**
     * @covers Parser::parse()
     * @throws PasetoException
     * @throws \Exception
     * @throws \ParagonIE\Paseto\Exception\RuleViolation
     * @throws \TypeError
     */
    public function testAuthToken()
    {
        $key = new SymmetricKey('YELLOW SUBMARINE, BLACK WIZARDRY');
        $v2key = new V2SymmetricKey('YELLOW SUBMARINE, BLACK WIZARDRY');
        // $nonce = crypto_generichash('Paragon Initiative Enterprises, LLC', '', 24);
        $nonce = Hex::decode('45742c976d684ff84ebdc0de59809a97cda2f64c84fda19b');

        $serialized = 'v2.local.3fNxan9FHjedQRSONRnT7Ce_KhhpB0NrlHwAGsCb54x0FhrjBfeNN4uPHFiO5H0iPCZSjwfEkkfiGeYpE6KAfr1Zm3G-VTe4lcXtgDyKATYULT-zLPfshRqisk4n7EbGufWuqilYvYXMCiYbaA';
        $parser = (new Parser())
            ->setPurpose(Purpose::local())
            ->setKey($key);
        $v2parser = (new Parser())
            ->setPurpose(Purpose::local())
            ->setKey($v2key);

        $token = $parser->parse($serialized);
        $v2token = $v2parser->parse($serialized);

        $builder = (Builder::getLocal($key, new Version2(), $token));
        NonceFixer::buildSetExplicitNonce()->bindTo($builder, $builder)($nonce);

        $v2builder = (Builder::getLocal($v2key, new Version2(), $v2token));
        NonceFixer::buildSetExplicitNonce()->bindTo($v2builder, $v2builder)($nonce);

        $this->assertSame(
            '2039-01-01T00:00:00+00:00',
            $token->getExpiration()->format(\DateTime::ATOM),
            'Mismatched expiration date/time'
        );
        $this->assertSame(
            '2039-01-01T00:00:00+00:00',
            $v2token->getExpiration()->format(\DateTime::ATOM),
            'Mismatched expiration date/time'
        );
        $this->assertSame(
            'this is a signed message',
            $token->get('data'),
            'Custom claim not found'
        );
        $this->assertSame($serialized, (string) $builder);
        $this->assertSame($serialized, (string) $v2builder);

        $this->assertTrue($parser->validate($token));
        $this->assertTrue($parser->validate($v2token));
        $parser->addRule(new NotExpired(new \DateTime('2007-01-01T00:00:00')));
        $v2parser->addRule(new NotExpired(new \DateTime('2007-01-01T00:00:00')));
        $this->assertTrue($parser->validate($token));
        $this->assertTrue($v2parser->validate($token));

        $cloned = clone $parser;
        $cloned->addRule(new NotExpired(new \DateTime('2050-01-01T23:59:59')));
        $this->assertFalse($cloned->validate($token));

        try {
            $cloned->parse($serialized);
            $this->fail('Validation logic is being ignored.');
        } catch (PasetoException $ex) {
        }
        $parser->parse($serialized);

        // Switch to asymmetric-key crypto:
        $builder->setPurpose(Purpose::public())
                ->setKey(new AsymmetricSecretKey('YELLOW SUBMARINE, BLACK WIZARDRY'), true);
        $v2builder->setPurpose(Purpose::public())
                ->setKey(new V2AsymmetricSecretKey('YELLOW SUBMARINE, BLACK WIZARDRY'), true);
        $this->assertSame(
            'v2.public.eyJkYXRhIjoidGhpcyBpcyBhIHNpZ25lZCBtZXNzYWdlIiwiZXhwIjoiMjAzOS0wMS0wMVQwMDowMDowMCswMDowMCJ9BAOu3lUQMVHnBcPSkuORw51yiGGQ3QFUMoJO9U0gRAdAOPQEZFsd0YM_GZuBcmrXEOD1Re-Ila8vfPrfM5S6Ag',
            (string) $builder,
            'Switching to signing caused a different signature'
        );
        $this->assertSame(
            'v2.public.eyJkYXRhIjoidGhpcyBpcyBhIHNpZ25lZCBtZXNzYWdlIiwiZXhwIjoiMjAzOS0wMS0wMVQwMDowMDowMCswMDowMCJ9BAOu3lUQMVHnBcPSkuORw51yiGGQ3QFUMoJO9U0gRAdAOPQEZFsd0YM_GZuBcmrXEOD1Re-Ila8vfPrfM5S6Ag',
            (string) $v2builder,
            'Switching to signing caused a different signature'
        );
    }

    /**
     * @throws PasetoException
     * @throws \Exception
     * @throws \TypeError
     */
    public function testTokenSignVerify()
    {
        $secretKey = new AsymmetricSecretKey('YELLOW SUBMARINE, BLACK WIZARDRY');
        $publicKey = $secretKey->getPublicKey();
        $parser = new Parser(ProtocolCollection::default(), Purpose::public(), $publicKey);
        $tainted = 'v2.public.eyJkYXRhIjoidGhpcyBpcyBhIHNpZ25lZCBtZXNzYWdlIiwiZXhwIjoiMjAzOS0wMS0wMVQwMDowMDowMCswMDowMCJ9BAOu3lUQMVHnBcPSkuORw51yiGGQ3QFUMoJO9U0gRAdAOPQEZFsd0YM_GZuBcmrXEOD1Re-Ila8vfPrfM5S6Ag';

        $token = $parser->parse($tainted);
        $this->assertInstanceOf(JsonToken::class, $token);
    }
}
