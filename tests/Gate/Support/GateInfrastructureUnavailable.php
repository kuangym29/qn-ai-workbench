<?php

declare(strict_types=1);

namespace Tests\Gate\Support;

use RuntimeException;

/**
 * Gate 的前置基础设施不可用（观测能力缺失），与「被验证的行为不通过」严格区分。
 *
 * 抛出它的语义是：**这次没有能力完成观测，因此 Gate 不算通过**，
 * 而不是「换个方式跑一遍让它绿」。调用方必须让它冒泡成 FAIL，
 * 绝不允许降级成顺序执行或跳过。
 */
final class GateInfrastructureUnavailable extends RuntimeException {}
