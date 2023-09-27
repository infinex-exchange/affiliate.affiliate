CREATE ROLE "affiliate.affiliate" LOGIN PASSWORD 'password';

create table reflinks(
    refid bigserial not null primary key,
    uid bigint not null,
    description varchar(255) not null,
    active boolean not null default true
);

GRANT SELECT, INSERT, UPDATE ON reflinks TO "affiliate.affiliate";

create table affiliations(
    slave_uid bigint not null,
    refid bigint not null,
    slave_level int not null,
    
    foreign key(refid) references reflinks(refid)
);

GRANT SELECT, INSERT ON affiliations TO "affiliate.affiliate";

create table affiliate_levels(
    levelid smallserial not null primary key,
    reward_spot decimal(11, 10),
    reward_mining decimal(11, 10),
    reward_nft decimal(11, 10),
    reward_nft_studio decimal(11, 10)
);

GRANT SELECT ON affiliate_levels TO "affiliate.affiliate";

create table affiliate_settlements(
    afseid bigserial not null primary key,
    month timestamptz not null,
    refid bigint not null,
    mastercoin_equiv decimal(65,32) default null
);

GRANT SELECT, INSERT ON affiliate_settlements TO "affiliate.affiliate";
GRANT SELECT, USAGE ON SEQUENCE affiliate_settlements_afseid_seq TO "affiliate.affiliate";

create table affiliate_rewards(
    afseid bigint not null,
    slave_level smallint not null,
    reward decimal(65,32) not null,
    assetid varchar(32) not null,
    reward_type varchar(32) not null,
    
    foreign key(afseid) references affiliate_settlements(afseid),
    foreign key(slave_level) references affiliate_levels(levelid)
);

GRANT SELECT, INSERT ON affiliate_rewards TO "affiliate.affiliate";

create table affiliate_slaves_snap(
    afseid bigint not null,
    slave_level smallint not null,
    slaves_count int not null,
    
    foreign key(afseid) references affiliate_settlements(afseid),
    foreign key(slave_level) references affiliate_levels(levelid)
);

GRANT SELECT, UPDATE, INSERT ON affiliate_slaves_snap TO "affiliate.affiliate";