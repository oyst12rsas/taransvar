use strict;
use warnings;
use JSON::PP qw(decode_json encode_json);
use Test::More;
open my $fh, '<', 'misc/gateway_ai_assess.pl' or die $!;
local $/; my $source = <$fh>;
my ($helper) = $source =~ /(sub validate_unit_identities \{.*?)(?=my \$cfg =)/s;
die 'Identity helper missing' unless $helper;
eval $helper; die $@ if $@;
my $question = "RECORDS: local stable unit summaries\n".encode_json({owner_id=>6,unit_id=>20})."\nGLOBAL TARASEC CONTEXT\n".encode_json({owner_id=>9,unit_id=>30});
for my $case (
    [[], 1], [[{owner_id=>6,unit_id=>20}],1],
    [[{owner_id=>'owner_id_example',unit_id=>'unit_id_example'}],0],
    [[{owner_id=>6,unit_id=>21}],0], [[{owner_id=>9,unit_id=>30}],0],
    [[undef],0], [undef,0]
) {
    my $ok = eval { validate_unit_identities({unit_assessments=>$case->[0]}, $question); 1 };
    is($ok ? 1 : 0, $case->[1], 'Only supplied local identities accepted');
}
done_testing();
