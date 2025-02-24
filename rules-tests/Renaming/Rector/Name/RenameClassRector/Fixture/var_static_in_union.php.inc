<?php

namespace Rector\Tests\Renaming\Rector\Name\RenameClassRector\Fixture;

use DateTime;
use DateTimeInterface;

class VarStaticInUnion extends \DateTime
{
    /**
     * @var DateTimeInterface|static|null
     */
    private $dateTime;

    public function __construct(DateTime $dateTime)
    {
        $this->dateTime = rand(0,30)
            ? $dateTime
            : null;
    }
}

?>
-----
<?php

namespace Rector\Tests\Renaming\Rector\Name\RenameClassRector\Fixture;

use DateTime;
use DateTimeInterface;

class VarStaticInUnion extends \DateTime
{
    /**
     * @var DateTimeInterface|static|null
     */
    private $dateTime;

    public function __construct(\DateTimeInterface $dateTime)
    {
        $this->dateTime = rand(0,30)
            ? $dateTime
            : null;
    }
}

?>
