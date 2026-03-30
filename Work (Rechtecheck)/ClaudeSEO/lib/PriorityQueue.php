<?php

class PriorityQueue {
    private Database $db;
    private SeoAnalyzer $analyzer;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->analyzer = new SeoAnalyzer();
    }

    /**
     * Build the priority queue by analyzing all published rechtsfragen.
     * Clears old unexecuted decisions first, then inserts new ones.
     */
    public function buildQueue(): int {
        // Clear old unexecuted decisions
        $this->db->query('DELETE FROM page_decisions WHERE executed_at IS NULL');

        $count = 0;

        // Analyze all rechtsfragen
        $rfList = $this->db->fetchAll('SELECT * FROM rechtsfragen');
        foreach ($rfList as $rf) {
            // Get 30-day analytics
            $analytics = $this->db->fetchOne(
                'SELECT SUM(clicks) as clicks, SUM(impressions) as impressions,
                        AVG(ctr) as ctr, AVG(avg_position) as avg_position
                 FROM page_analytics
                 WHERE page_type = ? AND page_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)',
                ['rechtsfrage', $rf['id']]
            );

            $result = $this->analyzer->analyzeRechtsfrage($rf, $analytics ?: []);

            // Only insert if there's an action to take (priority > 0)
            if ($result['priority'] > 0) {
                $this->db->insert('page_decisions', [
                    'page_type'      => 'rechtsfrage',
                    'page_id'        => $rf['id'],
                    'action'         => $result['action'],
                    'reason'         => $result['reason'],
                    'priority_score' => $result['priority'],
                    'decided_at'     => date('Y-m-d H:i:s'),
                ]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get the next N highest-priority unexecuted decisions.
     */
    public function getNextItems(int $limit = 10): array {
        return $this->db->fetchAll(
            'SELECT pd.*, rf.name as rf_name, rf.rechtsgebiet_id, rf.slug as rf_slug
             FROM page_decisions pd
             INNER JOIN rechtsfragen rf ON pd.page_id = rf.id AND pd.page_type = "rechtsfrage"
             WHERE pd.executed_at IS NULL
             ORDER BY pd.priority_score DESC
             LIMIT ?',
            [$limit]
        );
    }

    /**
     * Mark a decision as executed.
     */
    public function markExecuted(int $decisionId): void {
        $this->db->update('page_decisions',
            ['executed_at' => date('Y-m-d H:i:s')],
            'id = ?', [$decisionId]
        );
    }
}
