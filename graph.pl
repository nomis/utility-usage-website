#!/usr/bin/perl
use GD::Graph::bars;
use GD::Graph::bars3d;

my $xlabel = shift;
my $ylabel = shift;
$xlabel =~ s/\\(\'|\"|\^|\\)/$1/g;
$ylabel =~ s/\\(\'|\"|\^|\\)/$1/g;
my $max = 0;
my (@data);
#my (@values);
my $i = 0;
while (my $x = shift) {
	my ($l, $u, $c) = split(/,/,$x);
	$data[0][$i] = $l;
	$data[1][$i] = $u-$c;
	if ($c == 0) {
		$data[2][$i] = undef;
		$data[3][$i] = undef;
	} elsif ($c >= 0) {
		$data[2][$i] = undef;
		$data[3][$i] = $c;
	} else {
		if ($u == 0) {
			$data[1][$i] = undef;
			$data[2][$i] = -$c;
			$data[3][$i] = undef;
		} else {
			$data[2][$i] = $c;
			$data[3][$i] = undef;
		}
	}
#	$values[0][$i] = undef;
#	$values[1][$i] = $u;
	$t = $u;
	if ($c < 0) {
		$t = $t + -$c;
	}
	if ($t > $max) {
		$max = $t;
	}
	$i++;
}
$max = int($max + 1);

my $graph = GD::Graph::bars->new(1000, 400);
$graph->set_text_clr("black");
$graph->set_legend_font(GD::Text::gdTinyFont);
$graph->set(dclrs => [ qw(blue green red) ]);
$graph->set(
	x_label => $xlabel,
	y_label => $ylabel,
	bar_spacing => 2,
	y_tick_number => 10,
	y_label_skip => 1,
	y_max_value => $max,
	cumulate => 'true',
#	show_values => \@values,
#	values_vertical => 1,
#	values_space => 10,
#	values_format => "%.2f"
);
my $gd = $graph->plot(\@data) or die $graph->error;
print $gd->png;
