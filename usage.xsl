<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" version="1.0" exclude-result-prefixes="xsl xsi">
	<xsl:output method="html" version="5.0" encoding="UTF-8" indent="yes" doctype-system="about:legacy-compat"/>
	<xsl:template match="/*">
		<html>
			<head>
				<title><xsl:value-of select="usage/@name"/></title>
				<link rel="stylesheet" href="/normalize.css" type="text/css"/>
				<link rel="stylesheet" href="/usage.css" type="text/css"/>
			</head>
			<body>
				<xsl:apply-templates select="usage"/>
			</body>
		</html>
	</xsl:template>

	<xsl:template match="usage">
		<xsl:variable name="max_usage">
			<xsl:variable name="max_normal_usage">
				<xsl:for-each select="periods/period">
					<xsl:sort select="@usage" data-type="number" order="descending"/>
					<xsl:if test="position() = 1"><xsl:value-of select="@usage"/></xsl:if>
				</xsl:for-each>
			</xsl:variable>
			<xsl:variable name="max_compare_usage">
				<xsl:for-each select="periods/period">
					<xsl:sort select="@compare_usage" data-type="number" order="descending"/>
					<xsl:if test="position() = 1"><xsl:value-of select="@compare_usage"/></xsl:if>
				</xsl:for-each>
			</xsl:variable>
			<xsl:choose>
				<xsl:when test="count(periods/period/@compare_usage) = 0"><xsl:value-of select="$max_normal_usage"/></xsl:when>
				<xsl:when test="count(periods/period/@usage) = 0"><xsl:value-of select="$max_compare_usage"/></xsl:when>
				<xsl:when test="$max_normal_usage > $max_compare_usage"><xsl:value-of select="$max_normal_usage"/></xsl:when>
				<xsl:otherwise><xsl:value-of select="$max_compare_usage"/></xsl:otherwise>
			</xsl:choose>
		</xsl:variable>

		<xsl:choose>
			<xsl:when test="parent">
				<h1>
					<a>
						<xsl:attribute name="href"><xsl:value-of select="parent/@uri"/></xsl:attribute>
						<xsl:value-of select="substring(@name, 1, string-length(parent/@name))"/>
					</a>
					<xsl:value-of select="substring(@name, string-length(parent/@name) + 1)"/>
				</h1>
			</xsl:when>
			<xsl:otherwise>
				<h1><xsl:value-of select="@name"/></h1>
			</xsl:otherwise>
		</xsl:choose>
		<xsl:apply-templates select="periods" mode="graph">
			<xsl:with-param name="max_usage"><xsl:value-of select="$max_usage"/></xsl:with-param>
		</xsl:apply-templates>
		<xsl:apply-templates select="../graph"/>
		<xsl:apply-templates select="periods" mode="table"/>
	</xsl:template>

	<xsl:template name="median">
		<xsl:param name="nodes"/>
		<xsl:param name="attr"/>
		<xsl:variable name="count" select="count($nodes/@*[local-name()=$attr])"/>
		<xsl:variable name="middle" select="ceiling($count div 2)"/>
		<xsl:variable name="even" select="not($count mod 2)"/>

		<xsl:variable name="m1">
			<xsl:for-each select="$nodes/@*[local-name()=$attr]">
				<xsl:sort data-type="number"/>
				<xsl:if test="position() = $middle">
					<xsl:value-of select="."/>
				</xsl:if>
			</xsl:for-each>
		</xsl:variable>
		<xsl:variable name="m2">
			<xsl:for-each select="$nodes/@*[local-name()=$attr]">
				<xsl:sort data-type="number"/>
				<xsl:if test="position() = $middle + 1">
					<xsl:value-of select="."/>
				</xsl:if>
			</xsl:for-each>
		</xsl:variable>

		<xsl:value-of select="($m1 + $m2) div ($even + 1)"/>
	</xsl:template>

	<xsl:template match="periods" mode="table">
		<xsl:variable name="usage_median">
			<xsl:call-template name="median">
				<xsl:with-param name="nodes" select="period"/>
				<xsl:with-param name="attr" select="'usage'"/>
			</xsl:call-template>
		</xsl:variable>
		<table class="usage">
			<thead>
				<tr>
					<th scope="col" class="name"><xsl:value-of select="@type"/></th>
					<th scope="col" class="usage">Usage (<xsl:value-of select="/*/@units"/>)</th>
				</tr>
			</thead>
			<tbody>
				<xsl:apply-templates select="period/@usage/.." mode="table"/>
			</tbody>
			<tfoot>
				<xsl:if test="count(period/@usage) > 1">
					<tr class="summary average">
						<th scope="row" class="name">Average</th>
						<td class="usage"><xsl:value-of select="format-number(sum(period/@usage) div count(period/@usage), /*/@format)"/></td>
					</tr>
				</xsl:if>
				<xsl:if test="count(period/@usage) > 2">
					<tr class="summary median">
						<th scope="row" class="name">Median</th>
						<td class="usage"><xsl:value-of select="format-number($usage_median, /*/@format)"/></td>
					</tr>
				</xsl:if>
				<tr class="summary total">
					<th scope="row" class="name">Total</th>
					<td class="usage"><xsl:value-of select="format-number(sum(period/@usage), /*/@format)"/></td>
				</tr>
			</tfoot>
		</table>
	</xsl:template>

	<xsl:template match="period" mode="table">
		<tr>
			<th scope="row" class="name">
				<xsl:choose>
					<xsl:when test="@uri">
						<a>
							<xsl:attribute name="href"><xsl:value-of select="@uri"/></xsl:attribute>
							<xsl:value-of select="@name"/>
						</a>
					</xsl:when>
					<xsl:otherwise>
						<xsl:value-of select="@name"/>
					</xsl:otherwise>
				</xsl:choose>
			</th>
			<td class="usage"><xsl:value-of select="format-number(@usage, /*/@format)"/></td>
		</tr>
	</xsl:template>

	<xsl:template match="periods" mode="graph">
		<xsl:param name="max_usage"/>
		<xsl:variable name="svg_width" select="1200"/>
		<xsl:variable name="svg_height" select="400"/>
		<xsl:variable name="x_text_height">
			<xsl:choose>
				<xsl:when test="count(period) &lt;= 25">16</xsl:when>
				<xsl:otherwise>10</xsl:otherwise>
			</xsl:choose>
		</xsl:variable>
		<xsl:variable name="y_text_height" select="12"/>
		<xsl:variable name="x_label_height">
			<xsl:choose>
				<xsl:when test="count(period) &lt;= 25">30</xsl:when>
				<xsl:otherwise>20</xsl:otherwise>
			</xsl:choose>
		</xsl:variable>
		<xsl:variable name="x_tick_height" select="5"/>
		<xsl:variable name="y_label_width" select="70"/>
		<xsl:variable name="x_period_width" select="($svg_width - $y_label_width) div count(period)"/>
		<xsl:variable name="y_steps" select="10"/>
		<xsl:variable name="y_tick_width" select="5"/>
		<xsl:variable name="y_step_height" select="($svg_height - $x_label_height) div $y_steps"/>
		<xsl:variable name="bar_width" select="0.85"/>

		<svg xmlns="http://www.w3.org/2000/svg" version="1.1">
			<xsl:attribute name="width"><xsl:value-of select="$svg_width"/></xsl:attribute>
			<xsl:attribute name="height"><xsl:value-of select="$svg_height + $y_text_height"/></xsl:attribute>
			<xsl:attribute name="viewBox" xml:space="preserve">0 <xsl:value-of select="-($y_text_height)"/> <xsl:value-of select="$svg_width"/> <xsl:value-of select="$svg_height + $y_text_height"/></xsl:attribute>

			<g stroke="grey" stroke-width="1">
				<xsl:for-each select="period">
					<xsl:variable name="usage">
						<xsl:choose>
							<xsl:when test="@compare_usage and @usage > @compare_usage"><xsl:value-of select="@compare_usage"/></xsl:when>
							<xsl:when test="not(@usage)">0</xsl:when>
							<xsl:otherwise><xsl:value-of select="@usage"/></xsl:otherwise>
						</xsl:choose>
					</xsl:variable>
					<xsl:variable name="over_usage">
						<xsl:choose>
							<xsl:when test="@compare_usage and @usage > @compare_usage"><xsl:value-of select="@usage - @compare_usage"/></xsl:when>
							<xsl:otherwise>0</xsl:otherwise>
						</xsl:choose>
					</xsl:variable>
					<xsl:variable name="under_usage">
						<xsl:choose>
							<xsl:when test="not(@usage)"><xsl:value-of select="@compare_usage"/></xsl:when>
							<xsl:when test="@compare_usage or (@compare_usage > @usage)"><xsl:value-of select="@compare_usage - @usage"/></xsl:when>
							<xsl:otherwise>0</xsl:otherwise>
						</xsl:choose>
					</xsl:variable>
					<xsl:variable name="compare_offset">
						<xsl:choose>
							<xsl:when test="not(@usage)">0.5</xsl:when>
							<xsl:otherwise>1.5</xsl:otherwise>
						</xsl:choose>
					</xsl:variable>

					<!-- bars -->
					<xsl:if test="1">
						<xsl:if test="$over_usage > 0">
							<rect fill="firebrick">
								<xsl:attribute name="x"><xsl:value-of select="$y_label_width + 0.5 + floor(($x_period_width) * (position() - 1) + ($x_period_width - ($x_period_width * $bar_width)) div 2)"/></xsl:attribute>
								<xsl:attribute name="y"><xsl:value-of select="$compare_offset + ($svg_height - $x_label_height - 1) - floor(($svg_height - $x_label_height - 1) * ($usage + $over_usage) div $max_usage)"/></xsl:attribute>
								<xsl:attribute name="width"><xsl:value-of select="floor($x_period_width * $bar_width)"/></xsl:attribute>
								<xsl:attribute name="height"><xsl:value-of select="floor(($svg_height - $x_label_height - 1) * $over_usage div $max_usage)"/></xsl:attribute>
							</rect>
						</xsl:if>
						<xsl:if test="$under_usage > 0">
							<rect fill="limegreen">
								<xsl:attribute name="x"><xsl:value-of select="$y_label_width + 0.5 + floor(($x_period_width) * (position() - 1) + ($x_period_width - ($x_period_width * $bar_width)) div 2)"/></xsl:attribute>
								<xsl:attribute name="y"><xsl:value-of select="$compare_offset + ($svg_height - $x_label_height - 1) - floor(($svg_height - $x_label_height - 1) * ($usage + $under_usage) div $max_usage)"/></xsl:attribute>
								<xsl:attribute name="width"><xsl:value-of select="floor($x_period_width * $bar_width)"/></xsl:attribute>
								<xsl:attribute name="height"><xsl:value-of select="floor(($svg_height - $x_label_height - 1) * $under_usage div $max_usage)"/></xsl:attribute>
							</rect>
						</xsl:if>
						<xsl:if test="$usage > 0">
							<rect fill="mediumblue">
								<xsl:attribute name="x"><xsl:value-of select="$y_label_width + 0.5 + floor(($x_period_width) * (position() - 1) + ($x_period_width - ($x_period_width * $bar_width)) div 2)"/></xsl:attribute>
								<xsl:attribute name="y"><xsl:value-of select="0.5 + ($svg_height - $x_label_height - 1) - floor(($svg_height - $x_label_height - 1) * $usage div $max_usage)"/></xsl:attribute>
								<xsl:attribute name="width"><xsl:value-of select="floor($x_period_width * $bar_width)"/></xsl:attribute>
								<xsl:attribute name="height"><xsl:value-of select="floor(($svg_height - $x_label_height - 1) * $usage div $max_usage)"/></xsl:attribute>
							</rect>
						</xsl:if>
					</xsl:if>
				</xsl:for-each>
			</g>

			<g stroke="black" stroke-width="1">
				<!-- X axis -->
				<line>
					<xsl:attribute name="x1"><xsl:value-of select="$y_label_width"/></xsl:attribute>
					<xsl:attribute name="x2"><xsl:value-of select="$svg_width"/></xsl:attribute>
					<xsl:attribute name="y1"><xsl:value-of select="$svg_height - $x_label_height - 0.5"/></xsl:attribute>
					<xsl:attribute name="y2"><xsl:value-of select="$svg_height - $x_label_height - 0.5"/></xsl:attribute>
				</line>

				<!-- Y axis -->
				<line stroke="black" stroke-width="1">
					<xsl:attribute name="x1"><xsl:value-of select="$y_label_width - 0.5"/></xsl:attribute>
					<xsl:attribute name="x2"><xsl:value-of select="$y_label_width - 0.5"/></xsl:attribute>
					<xsl:attribute name="y1">0</xsl:attribute>
					<xsl:attribute name="y2"><xsl:value-of select="$svg_height - $x_label_height"/></xsl:attribute>
				</line>

				<!-- X ticks -->
				<line>
					<xsl:attribute name="x1"><xsl:value-of select="$y_label_width - 0.5"/></xsl:attribute>
					<xsl:attribute name="x2"><xsl:value-of select="$y_label_width - 0.5"/></xsl:attribute>
					<xsl:attribute name="y1"><xsl:value-of select="$svg_height - $x_label_height - $x_tick_height div 2 - 0.5"/></xsl:attribute>
					<xsl:attribute name="y2"><xsl:value-of select="$svg_height - $x_label_height + $x_tick_height div 2 - 0.5"/></xsl:attribute>
				</line>
				<xsl:for-each select="period">
					<line>
						<xsl:attribute name="x1"><xsl:value-of select="$y_label_width - 0.5 + floor($x_period_width * position())"/></xsl:attribute>
						<xsl:attribute name="x2"><xsl:value-of select="$y_label_width - 0.5 + floor($x_period_width * position())"/></xsl:attribute>
						<xsl:attribute name="y1"><xsl:value-of select="$svg_height - $x_label_height - $x_tick_height div 2 - 0.5"/></xsl:attribute>
						<xsl:attribute name="y2"><xsl:value-of select="$svg_height - $x_label_height + $x_tick_height div 2 - 0.5"/></xsl:attribute>
					</line>
				</xsl:for-each>

				<!-- Y ticks -->
				<xsl:for-each select="(//node())[$y_steps >= position() - 1]">
					<line>
						<xsl:attribute name="x1"><xsl:value-of select="$y_label_width - $y_tick_width div 2 - 0.5"/></xsl:attribute>
						<xsl:attribute name="x2"><xsl:value-of select="$y_label_width"/></xsl:attribute>
						<xsl:attribute name="y1"><xsl:value-of select="floor($y_step_height * (position() - 1)) - 0.5"/></xsl:attribute>
						<xsl:attribute name="y2"><xsl:value-of select="floor($y_step_height * (position() - 1)) - 0.5"/></xsl:attribute>
					</line>
				</xsl:for-each>
			</g>

			<!-- X axis labels -->
			<xsl:for-each select="period">
				<text text-anchor="middle" dy="0.3em">
					<xsl:attribute name="font-size"><xsl:value-of select="$x_text_height"/></xsl:attribute>
					<xsl:attribute name="x"><xsl:value-of select="$y_label_width - 0.5 + floor($x_period_width * (position() - 0.5))"/></xsl:attribute>
					<xsl:attribute name="y"><xsl:value-of select="$svg_height - $x_label_height div 2"/></xsl:attribute>
					<xsl:choose>
						<xsl:when test="@short_name">
							<xsl:value-of select="@short_name"/>
						</xsl:when>
						<xsl:otherwise>
							<xsl:value-of select="@name"/>
						</xsl:otherwise>
					</xsl:choose>
				</text>
			</xsl:for-each>

			<!-- Y axis labels -->
			<xsl:for-each select="(//node())[$y_steps >= position() - 1]">
				<text text-anchor="end" dy="0.3em">
					<xsl:attribute name="font-size"><xsl:value-of select="$y_text_height"/></xsl:attribute>
					<xsl:attribute name="x"><xsl:value-of select="$y_label_width - $y_tick_width"/></xsl:attribute>
					<xsl:attribute name="y"><xsl:value-of select="floor($y_step_height * (position() - 1))"/></xsl:attribute>
					<xsl:value-of select="format-number(($y_steps - (position() - 1)) div $y_steps * $max_usage, /*/@format)"/>
				</text>
			</xsl:for-each>

			<!-- Y axis type -->
			<text text-anchor="middle" style="writing-mode: tb">
				<xsl:variable name="x"><xsl:value-of select="$y_text_height"/></xsl:variable>
				<xsl:variable name="y"><xsl:value-of select="($svg_height - $x_label_height) div 2"/></xsl:variable>
				<xsl:attribute name="font-size"><xsl:value-of select="$y_text_height"/></xsl:attribute>
				<xsl:attribute name="x"><xsl:value-of select="$x"/></xsl:attribute>
				<xsl:attribute name="y"><xsl:value-of select="$y"/></xsl:attribute>
				<xsl:attribute name="transform" xml:space="preserve">rotate(180 <xsl:value-of select="$x"/> <xsl:value-of select="$y"/>)</xsl:attribute>
				<xsl:value-of select="/*/@units"/>
			</text>
		</svg>
	</xsl:template>

	<xsl:template match="graph">
		<img>
			<xsl:attribute name="src"><xsl:value-of select="@uri"/></xsl:attribute>
			<xsl:attribute name="alt"></xsl:attribute>
		</img>
	</xsl:template>
</xsl:stylesheet>
