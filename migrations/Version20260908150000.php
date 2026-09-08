<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add private community posts, interactions, informal polls and moderation reports.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE community_post (id INT AUTO_INCREMENT NOT NULL, author_id INT NOT NULL, type VARCHAR(255) NOT NULL, title VARCHAR(160) NOT NULL, body LONGTEXT NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', starts_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ends_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_COMMUNITY_POST_AUTHOR (author_id), INDEX idx_community_post_status_created (status, created_at), INDEX idx_community_post_type_status_created (type, status, created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE community_comment (id INT AUTO_INCREMENT NOT NULL, post_id INT NOT NULL, author_id INT NOT NULL, body LONGTEXT NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_COMMUNITY_COMMENT_POST (post_id), INDEX IDX_COMMUNITY_COMMENT_AUTHOR (author_id), INDEX idx_community_comment_post_status_created (post_id, status, created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE community_reaction (id INT AUTO_INCREMENT NOT NULL, post_id INT NOT NULL, user_id INT NOT NULL, type VARCHAR(255) NOT NULL, reacted_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_COMMUNITY_REACTION_POST (post_id), INDEX IDX_COMMUNITY_REACTION_USER (user_id), UNIQUE INDEX uniq_community_reaction_post_user (post_id, user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE community_poll_option (id INT AUTO_INCREMENT NOT NULL, post_id INT NOT NULL, label VARCHAR(255) NOT NULL, position INT NOT NULL, INDEX IDX_COMMUNITY_POLL_OPTION_POST (post_id), UNIQUE INDEX uniq_community_poll_option_position (post_id, position), UNIQUE INDEX uniq_community_poll_option_label (post_id, label), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE community_poll_vote (id INT AUTO_INCREMENT NOT NULL, post_id INT NOT NULL, option_id INT NOT NULL, voter_id INT NOT NULL, voted_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_COMMUNITY_POLL_VOTE_POST (post_id), INDEX IDX_COMMUNITY_POLL_VOTE_OPTION (option_id), INDEX IDX_COMMUNITY_POLL_VOTE_VOTER (voter_id), UNIQUE INDEX uniq_community_poll_vote_post_voter (post_id, voter_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE community_report (id INT AUTO_INCREMENT NOT NULL, reporter_id INT NOT NULL, post_id INT DEFAULT NULL, comment_id INT DEFAULT NULL, resolver_id INT DEFAULT NULL, reason LONGTEXT NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', resolved_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', open_marker INT DEFAULT 1, INDEX IDX_COMMUNITY_REPORT_REPORTER (reporter_id), INDEX IDX_COMMUNITY_REPORT_POST (post_id), INDEX IDX_COMMUNITY_REPORT_COMMENT (comment_id), INDEX IDX_COMMUNITY_REPORT_RESOLVER (resolver_id), INDEX idx_community_report_status_created (status, created_at), UNIQUE INDEX uniq_community_report_open_post (reporter_id, post_id, open_marker), UNIQUE INDEX uniq_community_report_open_comment (reporter_id, comment_id, open_marker), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE community_post ADD CONSTRAINT FK_COMMUNITY_POST_AUTHOR FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE community_comment ADD CONSTRAINT FK_COMMUNITY_COMMENT_POST FOREIGN KEY (post_id) REFERENCES community_post (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE community_comment ADD CONSTRAINT FK_COMMUNITY_COMMENT_AUTHOR FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE community_reaction ADD CONSTRAINT FK_COMMUNITY_REACTION_POST FOREIGN KEY (post_id) REFERENCES community_post (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE community_reaction ADD CONSTRAINT FK_COMMUNITY_REACTION_USER FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE community_poll_option ADD CONSTRAINT FK_COMMUNITY_POLL_OPTION_POST FOREIGN KEY (post_id) REFERENCES community_post (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE community_poll_vote ADD CONSTRAINT FK_COMMUNITY_POLL_VOTE_POST FOREIGN KEY (post_id) REFERENCES community_post (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE community_poll_vote ADD CONSTRAINT FK_COMMUNITY_POLL_VOTE_OPTION FOREIGN KEY (option_id) REFERENCES community_poll_option (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE community_poll_vote ADD CONSTRAINT FK_COMMUNITY_POLL_VOTE_VOTER FOREIGN KEY (voter_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE community_report ADD CONSTRAINT FK_COMMUNITY_REPORT_REPORTER FOREIGN KEY (reporter_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE community_report ADD CONSTRAINT FK_COMMUNITY_REPORT_POST FOREIGN KEY (post_id) REFERENCES community_post (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE community_report ADD CONSTRAINT FK_COMMUNITY_REPORT_COMMENT FOREIGN KEY (comment_id) REFERENCES community_comment (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE community_report ADD CONSTRAINT FK_COMMUNITY_REPORT_RESOLVER FOREIGN KEY (resolver_id) REFERENCES app_user (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_report DROP FOREIGN KEY FK_COMMUNITY_REPORT_REPORTER');
        $this->addSql('ALTER TABLE community_report DROP FOREIGN KEY FK_COMMUNITY_REPORT_POST');
        $this->addSql('ALTER TABLE community_report DROP FOREIGN KEY FK_COMMUNITY_REPORT_COMMENT');
        $this->addSql('ALTER TABLE community_report DROP FOREIGN KEY FK_COMMUNITY_REPORT_RESOLVER');
        $this->addSql('ALTER TABLE community_poll_vote DROP FOREIGN KEY FK_COMMUNITY_POLL_VOTE_POST');
        $this->addSql('ALTER TABLE community_poll_vote DROP FOREIGN KEY FK_COMMUNITY_POLL_VOTE_OPTION');
        $this->addSql('ALTER TABLE community_poll_vote DROP FOREIGN KEY FK_COMMUNITY_POLL_VOTE_VOTER');
        $this->addSql('ALTER TABLE community_poll_option DROP FOREIGN KEY FK_COMMUNITY_POLL_OPTION_POST');
        $this->addSql('ALTER TABLE community_reaction DROP FOREIGN KEY FK_COMMUNITY_REACTION_POST');
        $this->addSql('ALTER TABLE community_reaction DROP FOREIGN KEY FK_COMMUNITY_REACTION_USER');
        $this->addSql('ALTER TABLE community_comment DROP FOREIGN KEY FK_COMMUNITY_COMMENT_POST');
        $this->addSql('ALTER TABLE community_comment DROP FOREIGN KEY FK_COMMUNITY_COMMENT_AUTHOR');
        $this->addSql('ALTER TABLE community_post DROP FOREIGN KEY FK_COMMUNITY_POST_AUTHOR');
        $this->addSql('DROP TABLE community_report');
        $this->addSql('DROP TABLE community_poll_vote');
        $this->addSql('DROP TABLE community_poll_option');
        $this->addSql('DROP TABLE community_reaction');
        $this->addSql('DROP TABLE community_comment');
        $this->addSql('DROP TABLE community_post');
    }
}
