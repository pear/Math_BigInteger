<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * Pure-PHP arbitrary precission integer arithmetic library.
 *
 * Supports base-2, base-10, base-16, and base-256 numbers.  Negative numbers are supported in all publically accessable
 * functions save for modPow and modInverse.
 *
 * PHP versions 4 and 5
 *
 * {@internal Math_BigInteger currently uses base 2**15 to perform operations such as multiplication and division and
 * base 2**30 (ie. two base 2**15 digits) to perform addition and subtraction.  Negative numbers are
 * supported in add(), subtract(), multiply(), divide(), and compare().
 *
 * Useful resources are as follows:
 *
 *  - {@link http://www.cacr.math.uwaterloo.ca/hac/about/chap14.pdf Handbook of Applied Cryptography (HAC)}
 *  - {@link http://math.libtomcrypt.com/files/tommath.pdf Multi-Precision Math (MPM)}
 *  - Java's BigInteger classes.  See /j2se/src/share/classes/java/math in jdk-1_5_0-src-jrl.zip
 *
 * A few ideas for optimizations are as follows:
 *
 * <ul>
 *   <li>Use a higher base.  Using base 2**16 - the highest base that could be used without resorting
 *       to the use of floats (since PHP usually uses signed 32-bit integers) - doesn't seem practical
 *       since doing so would mean that the highest base that could be used for additions / subtractions
 *       is base 2**16.  Also, this would mean that code would need to be added to account for the fact
 *       that, as an example, when right shifted by one, 0x80000000 yields a negative number - not a
 *       positive one.  In PHP6, 64-bit integers could be used, but doing so would break PHP4/5 compatability.
 *       Floats could also be used.</li>
 *   <li>Use the comba method to reduce the number of operations.  Multi-Precision Math uses this quite
 *       extensively.
 *
 *       <ul>
 *         <li>{@link http://www.everything2.com/index.pl?node_id=1736418}</li>
 *       </ul></li>
 *   <li>The following suggests that there exists a faster algorithim for modular exponentiation when the base
 *       being used is fixed:
 *
 *       <ul>
 *         <li>{@link http://www.cacr.math.uwaterloo.ca/hac/about/chap14.pdf HAC 14.6.3}</li>
 *         <li>{@link http://www.daimi.au.dk/~ivan/FastExpproject.pdf Fast Exponentiation In practice - Section 4.1}</li>
 *       </ul></li>
 * </ul>}}
 *
 * Here's a quick 'n dirty example of how to use this library:
 * <code>
 * <?php
 *    include('Math_BigInteger.php');
 *
 *    $a = new Math_BigInteger(2);
 *    $b = new Math_BigInteger(3);
 *
 *    $c = $a->add($b);
 *
 *    echo $c->toString(); // outputs 5
 * ?>
 * </code>
 *
 * LICENSE: This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston,
 * MA  02111-1307  USA
 *
 * @category   Math
 * @package    Math_BigInteger
 * @author     Jim Wigginton
 * @copyright  MMVI Jim Wigginton
 * @license    http://www.gnu.org/licenses/lgpl.txt
 * @version    CVS: $Id$
 * @link       http://pear.php.net/package/Math_BigInteger
 */

// the following are used by _slidingWindow().

/**#@+
 * @access private
 * @see Math_BigInteger::_slidingWindow()
 */
/**
 * @see Math_BigInteger::_montgomery()
 * @see Math_BigInteger::_undoMontgomery()
 */
define('MONTGOMERY',0);
/**
 * @see Math_BigInteger::_barrett()
 */
define('BARRETT',1);
/**
 * @see Math_BigInteger::_mod2()
 */
define('POWEROF2',2);
/**
 * @see Math_BigInteger::_remainder()
 */
define('CLASSIC',3);
/**
 * @see Math_BigInteger::_copy()
 */
define('NONE',4);
/**#@-*/

/**#@+
 * @access private
 * @see Math_BigInteger::_montgomery()
 * @see Math_BigInteger::_barrett()
 */
/**
 * $cache[VARIABLE] tells us whether or not the cached data is still valid.
 */
define('VARIABLE',0);
/**
 * $cache[DATA] contains the cached data.
 */
define('DATA',1);
/**#@-*/

/**
 * Pure-PHP arbitrary precission integer arithmetic library. Supports base-2, base-10, base-16, and base-256
 * numbers.  Negative numbers are supported in all publically accessable functions save for modPow
 * and modInverse.
 *
 * @author  Jim Wigginton
 * @version 0.1
 * @access  public
 * @package Math_BigInteger
 */
class Math_BigInteger {
    /**
     * Holds the BigInteger's value.
     *
     * @var Array
     * @access private
     */
    var $value = array();

    /**
     * Holds the BigInteger's magnitude.
     *
     * @var Boolean
     * @access private
     */
    var $is_negative = false;

    /**
     * Converts base-2, base-10, base-16, and binary strings (eg. base-256) to BigIntegers.
     *
     * Here's a quick 'n dirty example:
     * <code>
     * <?php
     *    include('Math_BigInteger.php');
     *
     *    $a = new Math_BigInteger('0x32',16); // 50 in base-16
     *
     *    echo $a->toString(); // outputs 50
     * ?>
     * </code>
     *
     * @param optional $x base-10 number or base-$base number if $base set.
     * @param optional integer $base
     * @return Math_BigInteger
     * @access public
     */
    function Math_BigInteger($x = 0, $base = 10)
    {
        if ($x === 0) {
            return;
        }
        switch ($base) {
            // converts a base-2**8 (big endian / msb) number to base-2**15 (little endian / lsb)
            case 256:
                while (strlen($x)) {
                    $this->value[] = $this->_bytes2int($this->_base256_rshift($x,15));
                }
                break;
            case 16:
                if ($x{0} == '-') {
                    $this->is_negative = true;
                    $x = substr($x,1);
                }

                $x = preg_replace('#^(?:0x)?([A-Fa-f0-9]*).*#','$1',$x);
                $x = ( strlen($x) & 1 ) ? '0'.$x : $x;

                $temp = new Math_BigInteger(pack('H*',$x),256);
                $this->value = $temp->value;
                break;
            case 10:
                $temp = new Math_BigInteger();

                // array(18944,30517) is 10**9 in base-2**15.  10**9 is the closest to 2**30 we can get without passing it.
                $multiplier = new Math_BigInteger();
                $multiplier->value = array(18944,30517);

                if ($x{0} == '-') {
                    $this->is_negative = true;
                    $x = substr($x,1);
                }

                $x = preg_replace('#^([0-9]*).*#','$1',$x);
                $x = str_pad($x,strlen($x)+(8*strlen($x))%9,0,STR_PAD_LEFT);

                while (strlen($x)) {
                    $temp = $temp->multiply($multiplier);
                    $temp = $temp->add(new Math_BigInteger($this->_int2bytes(substr($x,0,9)),256));
                    $x = substr($x,9);
                }

                $this->value = $temp->value;
                break;
            case 2: // base-2 support implemented by Lluis Pamies - thanks!
                if ($x{0} == '-') {
                    $this->is_negative = true;
                    $x = substr($x,1);
                }

                $x = preg_replace('#^([01]*).*#','$1',$x);
                $x = str_pad($x,strlen($x)+(3*strlen($x))%4,0,STR_PAD_LEFT);

                $str = '0x';
                while (strlen($x)) {
                   $str.= substr($x, 0, 4);
                   $str.= dechex(bindec($part));
                   $x = substr($x, 4);
                }

                $temp = new Math_BigInteger($str, 16);
                $this->value = $temp->value;
                break;
            default:
                // base not supported, so we'll let $this == 0
        }
    }

    /**
     * Converts a BigInteger to a byte string (eg. base-256).
     *
     * Here's a quick 'n dirty example:
     * <code>
     * <?php
     *    include('Math_BigInteger.php');
     *
     *    $a = new Math_BigInteger('65');
     *
     *    echo $a->toBytes(); // outputs chr(65)
     * ?>
     * </code>
     *
     * @return String
     * @access public
     * @internal Converts a base-2**15 number to base-2**8
     */
    function toBytes()
    {
        if (!count($this->value)) {
            return '';
        }

        $result = $this->_int2bytes($this->value[count($this->value)-1]);

        $temp = $this->_copy();

        for ($i=count($temp->value)-2;$i>=0;$i--) {
            $temp->_base256_lshift($result,15);
            $result = $result | str_pad($temp->_int2bytes($temp->value[$i]),strlen($result),chr(0),STR_PAD_LEFT);
        }

        return $result;
    }

    /**
     * Converts a BigInteger to a base-10 number.
     *
     * Here's a quick 'n dirty example:
     * <code>
     * <?php
     *    include('Math_BigInteger.php');
     *
     *    $a = new Math_BigInteger('50');
     *
     *    echo $a->toString(); // outputs 50
     * ?>
     * </code>
     *
     * @return String
     * @access public
     * @internal Converts a base-2**15 number to base-10
     */
    function toString()
    {
        if (!count($this->value)) {
            return '0';
        }

        $result = ($this->is_negative) ? '-' : '';

        $temp = $this->_copy();

        $divisor = new Math_BigInteger();
        $divisor->value = array(18944,30517); // eg. 10**9

        while (count($temp->value)) {
            list($temp,$mod) = $temp->divide($divisor);
            $result = str_pad($this->_bytes2int($mod->toBytes()),9,0,STR_PAD_LEFT).$result;
        }

        return ltrim($result,0);
    }

    /**
     * Adds two BigIntegers.
     *
     * Here's a quick 'n dirty example:
     * <code>
     * <?php
     *    include('Math_BigInteger.php');
     *
     *    $a = new Math_BigInteger('10');
     *    $b = new Math_BigInteger('20');
     *
     *    $c = $a->add($b);
     *
     *    echo $c->toString(); // outputs 30
     * ?>
     * </code>
     *
     * @param Math_BigInteger $y
     * @return Math_BigInteger
     * @access public
     * @internal Performs base-2**30 addition
     */
    function add($y)
    {
        if (!is_a($y,'Math_BigInteger')) {
            return false;
        }

        // subtract, if appropriate
        if ( $this->is_negative != $y->is_negative ) {
            // is $y the negative number?
            $y_negative = $this->compare($y) > 0;

            $temp = $this->_copy();
            $y = $y->_copy();
            $temp->is_negative = $y->is_negative = false;

            $diff = $temp->compare($y);
            if ( !$diff ) {
                return new Math_BigInteger();
            }

            $temp = $temp->subtract($y);

            $temp->is_negative = ($diff > 0) ? !$y_negative : $y_negative;

            return $temp;
        }

        $result = new Math_BigInteger();
        $carry = 0;

        $size = max(count($this->value),count($y->value));
        $size+= $size%2; // rounds $size to the nearest 2.

        $x = array_pad($this->value,$size,0);
        $y = array_pad($y->value,$size,0);

        for ($i=0;$i<$size-1;$i+=2) {
            $sum = ($x[$i+1]<<15 | $x[$i]) + ($y[$i+1]<<15 | $y[$i]) + $carry;
            $carry = $sum >> 30; // eg. floor($sum / 2**30); only possible values (in any base) are 0 and 1
            $sum &= 0x3FFFFFFF;

            $result->value[] = $sum & 0x7FFF;
            $result->value[] = $sum >> 15;
        }

        if ($carry) {
            $result->value[] = $carry;
        }

        $result->is_negative = $this->is_negative;

        return $result->_normalize();
    }

    /**
     * Subtracts two BigIntegers.
     *
     * Here's a quick 'n dirty example:
     * <code>
     * <?php
     *    include('Math_BigInteger.php');
     *
     *    $a = new Math_BigInteger('10');
     *    $b = new Math_BigInteger('20');
     *
     *    $c = $a->subtract($b);
     *
     *    echo $c->toString(); // outputs -10
     * ?>
     * </code>
     *
     * @param Math_BigInteger $y
     * @return Math_BigInteger
     * @access public
     * @internal Performs base-2**30 subtraction
     */
    function subtract($y)
    {
        if (!is_a($y,'Math_BigInteger')) {
            return false;
        }

        // add, if appropriate
        if ( $this->is_negative != $y->is_negative ) {
            $is_negative = $y->compare($this) > 0;

            $temp = $this->_copy();
            $y = $y->_copy();
            $temp->is_negative = $y->is_negative = false;

            $temp = $temp->add($y);

            $temp->is_negative = $is_negative;

            return $temp;
        }

        $diff = $this->compare($y);

        if ( !$diff ) {
            return new Math_BigInteger();
        }

        // switch $this and $y around, if appropriate.
        if ( (!$this->is_negative && $diff < 0) || ($this->is_negative && $diff > 0) ) {
            $is_negative = $y->is_negative;

            $temp = $this->_copy();
            $y = $y->_copy();
            $temp->is_negative = $y->is_negative = false;

            $temp = $y->subtract($temp);
            $temp->is_negative = !$is_negative;

            return $temp;
        }

        $result = new Math_BigInteger();
        $carry = 0;

        $size = max(count($this->value),count($y->value));
        $size+= $size%2;

        $x = array_pad($this->value,$size,0);
        $y = array_pad($y->value,$size,0);

        for ($i=0;$i<$size-1;$i+=2) {
            $sum = ($x[$i+1]<<15 | $x[$i]) - ($y[$i+1]<<15 | $y[$i]) + $carry;
            $carry = $sum >> 30;
            $sum &= 0x3FFFFFFF;

            $result->value[] = $sum & 0x7FFF;
            $result->value[] = $sum >> 15;
        }

        // $carry shouldn't be anything other than zero, at this point, since we already made sure that $this
        // was bigger than $y.

        $result->is_negative = $this->is_negative;

        return $result->_normalize();
    }

    /**
     * Multiplies two BigIntegers
     *
     * Here's a quick 'n dirty example:
     * <code>
     * <?php
     *    include('Math_BigInteger.php');
     *
     *    $a = new Math_BigInteger('10');
     *    $b = new Math_BigInteger('20');
     *
     *    $c = $a->multiply($b);
     *
     *    echo $c->toString(); // outputs 200
     * ?>
     * </code>
     *
     * @param Math_BigInteger $x
     * @return Math_BigInteger
     * @access public
     * @internal Modeled after 'multiply' in MutableBigInteger.java.
     */
    function multiply($x)
    {
        if (!is_a($x,'Math_BigInteger')) {
            return false;
        }

        if ( !$this->compare($x) ) {
            return $this->_square();
        }

        $this_length = count($this->value);
        $x_length = count($x->value);

        if ( !$this_length || !$x_length ) { // a 0 is being multiplied
            return new Math_BigInteger();
        }

        $product = new Math_BigInteger();
        $product->value = $this->_array_repeat(0,$this_length+$x_length);

        // the following for loop could be removed if the for loop following it
        // (the one with nested for loops) initially set $i to 0, but
        // doing so would also make the result in one set of unnecessary adds,
        // since on the outermost loops first pass, $product->value[$k] is going
        // to always be 0

        $carry = 0;
        $i=0;

        for ($j=0, $k=$i;$j<$this_length;$j++, $k++) {
            $temp = $product->value[$k]+$this->value[$j]*$x->value[$i]+$carry;
            $product->value[$k] = $temp & 0x7FFF;
            $carry = $temp >> 15;
        }

        $product->value[$k] = $carry;


        // the above for loop is what the previous comment was talking about.  the
        // following for loop is the "one with nested for loops"

        for ($i=1;$i<$x_length;$i++) {
            $carry = 0;

            for ($j=0, $k=$i;$j<$this_length;$j++, $k++) {
                $temp = $product->value[$k]+$this->value[$j]*$x->value[$i]+$carry;
                $product->value[$k] = $temp & 0x7FFF;
                $carry = $temp >> 15;
            }

            $product->value[$k] = $carry;
        }

        $product->is_negative = $this->is_negative != $x->is_negative;

        return $product->_normalize();
    }

    /**
     * Squares a BigInteger
     *
     * Squaring can be done faster than multiplying a number by itself can be.  See
     * {@link http://www.cacr.math.uwaterloo.ca/hac/about/chap14.pdf HAC 14.2.4} /
     * {@link http://math.libtomcrypt.com/files/tommath.pdf MPM 5.3} for more information.
     *
     * @return Math_BigInteger
     * @access private
     */
    function _square()
    {
        if ( empty($this->value) ) {
            return new Math_BigInteger();
        }

        $max_index = count($this->value)-1;

        $square = new Math_BigInteger();
        $square->value = $this->_array_repeat(0,2*$max_index);

        for ($i=0;$i<=$max_index;$i++) {
            $temp = $square->value[2*$i]+$this->value[$i]*$this->value[$i];
            $square->value[2*$i] = $temp & 0x7FFF;
            $carry = floor($temp/0x8000);

            // note how we start from $i+1 instead of 0 as we do in multiplication.
            for ($j=$i+1;$j<=$max_index;$j++) {
                $temp = $square->value[$i+$j]+2*$this->value[$j]*$this->value[$i]+$carry;
                $square->value[$i+$j] = $temp & 0x7FFF;
                $carry = floor($temp/0x8000);
            }

            // the following line can yield values larger 2**15.  at this point, PHP should switch
            // over to floats.
            $square->value[$i+$max_index+1] = $carry;
        }

        return $square->_normalize();
    }

    /**
     * Divides two BigIntegers.
     *
     * Returns an array whose first element contains the quotient and whose second element contains the
     * "common residue".  If the remainder would be positive, the "common residue" and the remainder are the
     * same.  If the remainder would be negative, the "common residue" is equal to the sum of the remainder
     * and the divisor.
     *
     * Here's a quick 'n dirty example:
     * <code>
     * <?php
     *    include('Math_BigInteger.php');
     *
     *    $a = new Math_BigInteger('10');
     *    $b = new Math_BigInteger('20');
     *
     *    list($quotient,$remainder) = $a->divide($b);
     *
     *    echo $quotient->toString(); // outputs 0
     *    echo "\r\n";
     *    echo $remainder->toString(); // outputs 10
     * ?>
     * </code>
     *
     * @param Math_BigInteger $y
     * @return Array
     * @access public
     * @internal This function is based off of {@link http://www.cacr.math.uwaterloo.ca/hac/about/chap14.pdf HAC 14.20}
     *    with a slight variation due to the fact that this script, initially, did not support negative numbers.  Now,
     *    it does, but I don't want to change that which already works.
     */
    function divide($y)
    {
        if (!is_a($y,'Math_BigInteger')) {
            return false;
        }

        $x = $this->_copy();
        $y = $y->_copy();

        $x_sign = $x->is_negative;
        $y_sign = $y->is_negative;

        $x->is_negative = $y->is_negative = false;

        $diff = $x->compare($y);

        if ( !$diff ) {
            $temp = new Math_BigInteger();
            $temp->value = array(1);
            $temp->is_negative = $x_sign != $y_sign;
            return array($temp,new Math_BigInteger());
        }

        if ( $diff < 0 ) {
            // if $x is negative, "add" $y.
            if ( $x_sign ) {
                $x = $y->subtract($x);
            }
            return array(new Math_BigInteger(),$x);
        }

        // normalize $x and $y as described in HAC 14.23 / 14.24
        // (incidently, i haven't been able to find a definitive example showing that this
        // results in worth-while speedup, but whatever)
        $msb = $y->value[count($y->value)-1];
        for ($shift=0;!($msb & 0x4000);$shift++) {
            $msb <<= 1;
        }
        $x->_lshift($shift);
        $y->_lshift($shift);

        $x_max = count($x->value)-1;
        $y_max = count($y->value)-1;

        $quotient = new Math_BigInteger();
        $quotient->value = $this->_array_repeat(0,$x_max-$y_max+1);

        // $temp = $y<<($x_max-$y_max-1) in base 2**15
        $temp = new Math_BigInteger();
        $temp->value = array_merge($this->_array_repeat(0,$x_max-$y_max),$y->value);

        while ( $x->compare($temp) >= 0 ) {
            // calculate the "common residue"
            $quotient->value[$x_max-$y_max]++;
            $x = $x->subtract($temp);
            $x_max = count($x->value)-1;
        }

        for ($i=$x_max;$i>=$y_max+1;$i--) {
            $q_index = $i-$y_max-1;
            if ($x->value[$i] == $y->value[$y_max]) {
                $quotient->value[$q_index] = 0x7FFF;
            } else {
                $quotient->value[$q_index] = floor(
                    ($x->value[$i]<<15 | $x->value[$i-1])
                    /
                    $y->value[$y_max]
                );
            }

            while (
                $quotient->value[$q_index]*($y->value[$y_max]<<15 | $y->value[$y_max-1])
                >
                $x->value[$i]*0x40000000 + ($x->value[$i-1]<<15 | $x->value[$i-2])
            ) {
                $quotient->value[$q_index]--;
            }

            $corrector = new Math_BigInteger();
            $temp = new Math_BigInteger();
            $corrector->value = $temp->value = $this->_array_repeat(0,$q_index);
            $temp->value[] = $quotient->value[$q_index];

            $temp = $temp->multiply($y);

            if ( $x->compare($temp) < 0 ) {
                $corrector->value[] = 1;
                $x = $x->add($corrector->multiply($y));
                $quotient->value[$q_index]--;
            }

            $x = $x->subtract($temp);
            $x_max = count($x->value)-1;
        }

        // unnormalize the remainder
        $x->_rshift($shift);

        $quotient->is_negative = $x_sign != $y_sign;

        // calculate the "common residue", if appropriate
        if ( $x_sign ) {
            $y->_rshift($shift);
            $x = $y->subtract($x);
        }

        return array($quotient->_normalize(),$x);
    }

    /**
     * Performs modular exponentiation.
     *
     * Here's a quick 'n dirty example:
     * <code>
     * <?php
     *    include('Math_BigInteger.php');
     *
     *    $a = new Math_BigInteger('10');
     *    $b = new Math_BigInteger('20');
     *    $c = new Math_BigInteger('30');
     *
     *    $c = $a->modPow($b,$c);
     *
     *    echo $c->toString(); // outputs 10
     * ?>
     * </code>
     *
     * @param Math_BigInteger $e
     * @param Math_BigInteger $n
     * @return Math_BigInteger
     * @access public
     * @internal The most naive approach to modular exponentiation has very unreasonable requirements, and
     *    and although the approach involving repeated squaring does vastly better, it, too, is impractical
     *    for our purposes.  The reason being that division - by far the most complicated and time-consuming
     *    of the basic operations (eg. +,-,*,/) - occurs multiple times within it.
     *
     *    Modular reductions resolve this issue.  Although an individual modular reduction takes more time
     *    then an individual division, when performed in succession (with the same modulo), they're a lot faster.
     *
     *    The two most commonly used modular reductions are Barrett and Montgomery reduction.  Montgomery reduction,
     *    although faster, only works when the gcd of the modulo and of the base being used is 1.  In RSA, when the
     *    base is a power of two, the modulo - a product of two primes - is always going to have a gcd of 1 (because
     *    the product of two odd numbers is odd), but what about when RSA isn't used?
     *
     *    In contrast, Barrett reduction has no such constraint.  As such, some bigint implementations perform a
     *    Barrett reduction after every operation in the modpow function.  Others perform Barrett reductions when the
     *    modulo is even and Montgomery reductions when the modulo is odd.  BigInteger.java's modPow method, however,
     *    uses a trick involving the Chinese Remainder Theorem to factor the even modulo into two numbers - one odd and
     *    the other, a power of two - and recombine them, later.  This is the method that this modPow function uses.
     *    {@link http://islab.oregonstate.edu/papers/j34monex.pdf Montgomery Reduction with Even Modulus} elaborates.
     */
    function modPow($e,$n)
    {
        if (!is_a($e,'Math_BigInteger') || !is_a($n,'Math_BigInteger')) {
            return false;
        }

        if ( empty($e->value) ) {
            $temp = new Math_BigInteger();
            $temp->value = array(1);
            return $temp;
        }

        if ( $e->value == array(1) ) {
            list(,$temp) = $this->divide($n);
            return $temp;
        }

        if ( $e->value == array(2) ) {
            $temp = $this->_square();
            list(,$temp) = $temp->divide($n);
            return $temp;
        }

        // is the modulo odd?
        if ( $n->value[0]&1 ) {
            return $this->_slidingWindow($e,$n,MONTGOMERY);
        }
        // if it's not, it's even

        // find the lowest set bit (eg. the max pow of 2 that divides $n)
        for ($i=0;$i<count($n->value);$i++) {
            if ( $n->value[$i] ) {
                $temp = decbin($n->value[$i]);
                $j = strlen($temp)-strrpos($temp,'1')-1;
                $j+= 15*$i;
                break;
            }
        }
        // at this point, 2^$j * $n/(2^$j) == $n

        $mod1 = $n->_copy();
        $mod1->_rshift($j);
        $mod2 = new Math_BigInteger();
        $mod2->value = array(1);
        $mod2->_lshift($j);

        $part1 = ( $mod1->value != array(1) ) ? $this->_slidingWindow($e,$mod1,MONTGOMERY) : new Math_BigInteger();
        $part2 = $this->_slidingWindow($e,$mod2,POWEROF2);

        $y1 = $mod2->modInverse($mod1);
        $y2 = $mod1->modInverse($mod2);

        $result = $part1->multiply($mod2);
        $result = $result->multiply($y1);

        $temp = $part2->multiply($mod1);
        $temp = $temp->multiply($y2);

        $result = $result->add($temp);
        list(,$result) = $result->divide($n);

        return $result;
    }

    /**
     * Sliding Window k-ary Modular Exponentiation
     *
     * Based on {@link http://www.cacr.math.uwaterloo.ca/hac/about/chap14.pdf HAC 14.85} /
     * {@link http://math.libtomcrypt.com/files/tommath.pdf MPM 7.7}.  In a departure from those algorithims,
     * however, this function performs a modular reduction after every multiplication and squaring operation.
     * As such, this function has the same preconditions that the reductions being used do.
     *
     * The window size is calculated in the same fashion that the window size in BigInteger.java's oddModPow
     * function is.
     *
     * @param Math_BigInteger $e
     * @param Math_BigInteger $n
     * @param Integer $mode
     * @return Math_BigInteger
     * @access private
     */
    function _slidingWindow($e,$n,$mode)
    {
        static $window_ranges = array(7,25,81,241,673,1793);

        $e_length = count($e->value)-1;
        $e_bits = decbin($e->value[$e_length]);
        for ($i=$e_length-1;$i>=1;$i-=2) {
            $e_bits.= str_pad(decbin($e->value[$i]<<15 | $e->value[$i-1]),30,'0',STR_PAD_LEFT);
        }
        if ($i==0) {
            $e_bits.= str_pad(decbin($e->value[$i]),15,'0',STR_PAD_LEFT);
        }
        $e_length = strlen($e_bits);

        // calculate the appropriate window size.
        // $window_size == 3 if $window_ranges is between 25 and 81, for example.
        for ($i=0, $window_size=1;$e_length > $window_ranges[$i] && $i < count($window_ranges);$window_size++, $i++);

        switch ($mode) {
            case MONTGOMERY:
                $reduce = '_montgomery';
                $undo = '_undoMontgomery';
                break;
            case BARRETT:
                $reduce = '_barrett';
                $undo = '_barrett';
                break;
            case POWEROF2:
                $reduce = '_mod2';
                $undo = '_mod2';
                break;
            case CLASSIC:
                $reduce = '_remainder';
                $undo = '_remainder';
                break;
            case NONE:
                // ie. do no modular reduction.  useful if you want to just do pow as opposed to modPow.
                $reduce = '_copy';
                $undo = '_copy';
                break;
            default:
                // an invalid $mode was provided
        }

        // precompute $this^0 through $this^$window_size
        $powers = array();
        $powers[1] = $this->$undo($n);
        $powers[2] = $powers[1]->_square();
        $powers[2] = $powers[2]->$reduce($n);

        // we do every other number since substr($e_bits,$i,$j+1) (see below) is supposed to end
        // in a 1.  ie. it's supposed to be odd.
        $temp = 1<<($window_size-1);
        for ($i=1;$i<$temp;$i++) {
            $powers[2*$i+1] = $powers[2*$i-1]->multiply($powers[2]);
            $powers[2*$i+1] = $powers[2*$i+1]->$reduce($n);
        }

        $result = new Math_BigInteger();
        $result->value = array(1);
        $result = $result->$undo($n);

        for ($i=0; $i < $e_length;) {
            if ( !$e_bits{$i} ) {
                $result = $result->_square();
                $result = $result->$reduce($n);
                $i++;
            } else {
                for ($j=$window_size-1;$j>=0;$j--) {
                    if ( $e_bits{$i+$j} ) {
                        break;
                    }
                }

                for ($k=0;$k<=$j;$k++) {// eg. the length of substr($e_bits,$i,$j+1)
                    $result = $result->_square();
                    $result = $result->$reduce($n);
                }

                $result = $result->multiply($powers[bindec(substr($e_bits,$i,$j+1))]);
                $result = $result->$reduce($n);

                $i+=$j+1;
            }
        }

        $result = $result->$reduce($n);
        return $result->_normalize();
    }

    /**
     * Remainder
     *
     * A wrapper for the divide function.
     *
     * @see divide()
     * @see _slidingWindow()
     * @access private
     * @param Math_BigInteger
     * @return Math_BigInteger
     */
    function _remainder($n)
    {
        list(,$temp) = $this->divide($n);
        return $temp;
    }

    /**
     * Modulos for Powers of Two
     *
     * Calculates $x%$n, where $n = 2^$e, for some $e.  Since this is basically the same as doing $x & ($n-1),
     * we'll just use this function as a wrapper for doing that.
     *
     * @see _slidingWindow()
     * @access private
     * @param Math_BigInteger
     * @return Math_BigInteger
     */
    function _mod2($n)
    {
        $temp = new Math_BigInteger();
        $temp->value = array(1);
        return $this->_and($n->subtract($temp));
    }

    /**
     * Barrett Modular Reduction
     *
     * See {@link http://www.cacr.math.uwaterloo.ca/hac/about/chap14.pdf HAC 14.3.3} /
     * {@link http://math.libtomcrypt.com/files/tommath.pdf MPM 6.2.5} for more information.  Modified slightly,
     * so as not to require negative numbers (initially, this script didn't support negative numbers).
     *
     * @see _slidingWindow()
     * @access private
     * @param Math_BigInteger
     * @return Math_BigInteger
     */
    function _barrett($n)
    {
        static $cache;

        $n_length = count($n->value);

        if ( !isset($cache[VARIABLE]) || $n->compare($cache[VARIABLE]) ) {
            $cache[VARIABLE] = $n;
            $temp = new Math_BigInteger();
            $temp->value = $this->_array_repeat(0,2*$n_length);
            $temp->value[] = 1;
            list($cache[DATA],) = $temp->divide($n);
        }

        $temp = new Math_BigInteger();
        $temp->value = array_slice($this->value,$n_length-1);
        $temp = $temp->multiply($cache[DATA]);
        $temp->value = array_slice($temp->value,$n_length+1);

        $result = new Math_BigInteger();
        $result->value = array_slice($this->value,0,$n_length+1);
        $temp = $temp->multiply($n);
        $temp->value = array_slice($temp->value,0,$n_length+1);

        if ($result->compare($temp) < 0) {
            $corrector = new Math_BigInteger();
            $corrector->value = $this->_array_repeat(0,$n_length+1);
            $corrector->value[] = 1;
            $result = $result->add($corrector);
        }

        $result = $result->subtract($temp);
        while ($result->compare($n) > 0) {
            $result = $result->subtract($n);
        }

        return $result;
    }

    /**
     * Montgomery Modular Reduction
     *
     * ($this->_montgomery($n))->_undoMontgomery($n) yields $x%$n.
     * {@link http://math.libtomcrypt.com/files/tommath.pdf MPM 6.3} provides insights on how this can be
     * improved upon (basically, by using the comba method).  gcd($n,2) must be equal to one for this function
     * to work correctly.
     *
     * @see _undoMontgomery()
     * @see _slidingWindow()
     * @access private
     * @param Math_BigInteger
     * @return Math_BigInteger
     */
    function _montgomery($n)
    {
        static $cache;

        if ( !isset($cache[VARIABLE]) || $n->compare($cache[VARIABLE]) ) {
            $cache[VARIABLE] = $n;
            $cache[DATA] = $n->_modInverse32768();
        }

        $result = $this->_copy();

        $n_length = count($n->value);

        for ($i=0;$i<$n_length;$i++) {
            $temp = new Math_BigInteger();
            $temp->value = array(
                ($result->value[$i]*$cache[DATA])&0x7FFF
            );
            $temp = $temp->multiply($n);
            $temp->value = array_merge($this->_array_repeat(0,$i),$temp->value);
            $result = $result->add($temp);
        }

        $result->value = array_slice($result->value,$n_length);

        if ($result->compare($n) >= 0) {
            $result = $result->subtract($n);
        }

        return $result->_normalize();
    }

    /**
     * Undo Montgomery Modular Reduction
     *
     * @see _montgomery()
     * @see _slidingWindow()
     * @access private
     * @param Math_BigInteger
     * @return Math_BigInteger
     */
    function _undoMontgomery($n)
    {
        $temp = new Math_BigInteger();
        $temp->value = array_merge($this->_array_repeat(0,count($n->value)),$this->value);
        list(,$temp) = $temp->divide($n);
        return $temp->_normalize();
    }

    /**
     * Modular Inverse of a number mod 2**15 (eg. 32768)
     *
     * Based off of the bnpInvDigit function implemented and justified in the following URL:
     *
     * {@link http://www-cs-students.stanford.edu/~tjw/jsbn/jsbn.js}
     *
     * @see _montgomery()
     * @access private
     * @return Integer
     */
    function _modInverse32768() // 2**15 == 32768
    {
        // remove the negative sign to make this function return the true multiplicative inverse
        $x = -$this->value[0];
        $result = $x & 0x3; // x^-1 mod 2^2
        $result = ($result*(2-($x & 0xF)*$result)) & 0xF; // x^-1 mod 2^4
        $result = ($result*(2-($x & 0xFF)*$result)) & 0xFF; // x^-1 mod 2^8
        $result = ($result*(2-((($x & 0x7FFF)*$result) & 0x7FFF))) & 0x7FFF; // x^-1 mod 2^15
        return $result;    
    }

    /**
     * Calculates modular inverses.
     *
     * Here's a quick 'n dirty example:
     * <code>
     * <?php
     *    include('Math_BigInteger.php');
     *
     *    $a = new Math_BigInteger(30);
     *    $b = new Math_BigInteger(17);
     *
     *    $c = $a->modInverse($b);
     *
     *    echo $c->toString(); // outputs 4
     * ?>
     * </code>
     *
     * @param Math_BigInteger $n
     * @return mixed false, if no modular inverse exists, Math_BigInteger, otherwise.
     * @access public
     * @internal Calculates the modular inverse of $this mod $n using the binary xGCD algorithim described in
     *    {@link http://www.cacr.math.uwaterloo.ca/hac/about/chap14.pdf HAC 14.61}.  As the text above 14.61 notes,
     *    the more traditional algorithim requires "relatively costly multiple-precision divisions".  See
     *    {@link http://www.cacr.math.uwaterloo.ca/hac/about/chap14.pdf HAC 14.64} for more information.
     */
    function modInverse($n)
    {
        if (!is_a($n,'Math_BigInteger')) {
            return false;
        }

        // if $this and $n are even, return false.
        if ( !($this->value[0]&1) && !($n->value[0]&1) ) {
            return false;
        }

        $u = $n->_copy();
        $x = $this;
        //list(,$x) = $this->divide($n);
        $v = $x->_copy();

        $a = new Math_BigInteger();
        $b = new Math_BigInteger();
        $c = new Math_BigInteger();
        $d = new Math_BigInteger();

        $a->value = $d->value = array(1);

        while ( !empty($u->value) ) {
            while ( !($u->value[0]&1) ) {
                $u->_rshift(1);
                if ( ($a->value[0]&1) || ($b->value[0]&1) ) {
                    $a = $a->add($x);
                    $b = $b->subtract($n);
                }
                $a->_rshift(1);
                $b->_rshift(1);
            }

            while ( !($v->value[0]&1) ) {
                $v->_rshift(1);
                if ( ($c->value[0]&1) || ($d->value[0]&1) ) {
                    $c = $c->add($x);
                    $d = $d->subtract($n);
                }
                $c->_rshift(1);
                $d->_rshift(1);
            }

            if ($u->compare($v) >= 0) {
                $u = $u->subtract($v);
                $a = $a->subtract($c);
                $b = $b->subtract($d);
            } else {
                $v = $v->subtract($u);
                $c = $c->subtract($a);
                $d = $d->subtract($b);
            }

            $u->_normalize();
        }

        // at this point, $v == gcd($this,$n).  if it's not equal to 1, no modular inverse exists.
        if ( $v->value != array(1) ) {
            return false;
        }

        return ($d->compare(new Math_BigInteger()) < 0) ? $d->add($n) : $d;
    }

    /**
     * Compares two numbers.
     *
     * @param Math_BigInteger $x
     * @return Integer < 0 if $this is less than $x; > 0 if $this is greater than $x, and 0 if they are equal.
     * @access public
     * @internal Could return $this->sub($x), but that's not as fast as what we do do.
     */
    function compare($x)
    {
        if (!is_a($x,'Math_BigInteger')) {
            return false;
        }

        $this->_normalize();
        $x->_normalize();

        if ( $this->is_negative != $x->is_negative ) {
            return ( !$this->is_negative && $x->is_negative ) ? 1 : -1;
        }

        $result = $this->is_negative ? -1 : 1;

        if ( count($this->value) != count($x->value) ) {
            return ( count($this->value) > count($x->value) ) ? $result : -$result;
        }

        for ($i=count($this->value)-1;$i>=0;$i--) {
            if ($this->value[$i] != $x->value[$i]) {
                return ( $this->value[$i] > $x->value[$i] ) ? $result : -$result;
            }
        }

        return 0;
    }

    /**
     * Returns a copy of $this
     *
     * PHP5 passes objects by reference while PHP4 passes by value.  As such, we need a function to guarantee
     * that all objects are passed by value, when appropriate.  More information can be found here:
     *
     * {@link http://www.php.net/manual/en/language.oop5.basic.php#51624}
     *
     * @access private
     * @return Math_BigInteger
     */
    function _copy()
    {
        $temp = new Math_BigInteger();
        $temp->value = $this->value;
        $temp->is_negative = $this->is_negative;
        return $temp;
    }

    /**
     * Logical And (base-256)
     *
     * @access private
     * @return String
     */
    function _and($x)
    {
        $result = new Math_BigInteger();

        $x_length = count($x->value);
        for ($i=0;$i<$x_length;$i++) {
            $result->value[] = $this->value[$i] & $x->value[$i];
        }

        return $result->_normalize();
    }

    /**
     * Logical Left Shift
     *
     * Shifts BigInteger's by $shift bits.
     *
     * @param Integer $shift
     * @return String
     * @access private
     */
    function _lshift($shift)
    {
        if ( $shift == 0 ) {
            return;
        }

        $num_digits = floor($shift/15);
        $shift %= 15;

        $carry = 0;
        for ($i=0;$i<count($this->value);$i++) {
            $temp = $this->value[$i] << $shift | $carry;
            $this->value[$i] = $temp & 0x7FFF;
            $carry = $temp >> 15;
        }

        if ( $carry != 0 ) {
            $this->value[] = $carry;
        }

        while ($num_digits--) {
            array_unshift($this->value,0);
        }
    }

    /**
     * Logical Right Shift
     *
     * Shifts BigInteger's by $shift bits.
     *
     * @param Integer $shift
     * @return String
     * @access private
     */
    function _rshift($shift) {
        if ($shift == 0) {
            $this->_normalize();
        }

        $num_digits = floor($shift/15);
        $shift %= 15;

        if ($num_digits) {
            $this->value = array_slice($this->value,$num_digits);
        }

        $carry = 0;
        $carry_shift = 15-$shift;

        for ($i=count($this->value)-1;$i>=0;$i--) {
            $temp = $this->value[$i] >> $shift | $carry;
            $carry = ($this->value[$i] << $carry_shift) & 0x7FFF;
            $this->value[$i] = $temp & 0x7FFF;
        }

        $this->_normalize();
    }

    /**
     * Normalize
     *
     * Deletes leading zeros.
     *
     * @see divide()
     * @return Math_BigInteger
     * @access private
     */
    function _normalize()
    {
        if ( !count($this->value) ) {
            return $this;
        }

        for ($i=count($this->value)-1;$i>=0;$i--) {
            if ( $this->value[$i] ) {
                break;
            }
            unset($this->value[$i]);
        }

        return $this;
    }

    /**
     * Array Repeat
     *
     * @param $input Array
     * @param $multiplier mixed
     * @return Array
     * @access private
     */
    function _array_repeat($input,$multiplier)
    {
        return ($multiplier) ? array_fill(0,$multiplier,$input) : array();
    }

    /**
     * Logical Left Shift
     *
     * Shifts binary strings $shift bits, essentially multiplying by 2**$shift.
     *
     * @param $x String
     * @param $shift Integer
     * @return String
     * @access private
     */
    function _base256_lshift(&$x, $shift)
    {
        if ($shift == 0) {
            return;
        }

        $num_bytes = $shift >> 3; // eg. floor($shift/8)
        $shift &= 7; // eg. $shift % 8

        $carry = 0;
        for ($i=strlen($x)-1;$i>=0;$i--) {
            $temp = ord($x{$i}) << $shift | $carry;
            $x{$i} = chr($temp);
            $carry = $temp >> 8;
        }
        $carry = ($carry != 0) ? chr($carry) : '';
        $x = $carry.$x.str_repeat(chr(0),$num_bytes);
    }

    // _base256_rshift() essentially divides by 2**$shift and returns the remainder.

    /**
     * Logical Right Shift
     *
     * Shifts binary strings $shift bits, essentially dividing by 2**$shift and returning the remainder.
     *
     * @param $x String
     * @param $shift Integer
     * @return String
     * @access private
     */
    function _base256_rshift(&$x, $shift)
    {
        if ($shift == 0) {
            $x = ltrim($x,chr(0));
            return '';
        }

        $num_bytes = $shift >> 3; // eg. floor($shift/8)
        $shift &= 7; // eg. $shift % 8

        $remainder = '';
        if ($num_bytes) {
            $remainder = substr($x,-$num_bytes);
            $x = substr($x,0,-$num_bytes);
        }

        $carry = 0;
        $carry_shift = 8-$shift;
        for ($i=0;$i<strlen($x);$i++) {
            $temp = (ord($x{$i}) >> $shift) | $carry;
            $carry = (ord($x{$i}) << $carry_shift) & 0xFF;
            $x{$i} = chr($temp);
        }
        $x = ltrim($x,chr(0));

        $remainder = chr($carry >> $carry_shift).$remainder;

        return ltrim($remainder,chr(0));
    }

    // one quirk about how the following functions are implemented is that PHP defines N to be an unsigned long
    // at 32-bits, while java's longs are 64-bits.

    /**
     * Converts 32-bit integers to bytes.
     *
     * @param Integer $x
     * @return String
     * @access private
     */
    function _int2bytes($x)
    {
        return ltrim(pack('N',$x),chr(0));
    }

    /**
     * Converts bytes to 32-bit integers
     *
     * @param String $x
     * @return Integer
     * @access private
     */
    function _bytes2int($x)
    {
        $temp = unpack('Nint',str_pad($x,4,chr(0),STR_PAD_LEFT));
        return $temp['int'];
    }
}

// vim: ts=4:sw=4:et:
// vim6: fdl=1:
?>