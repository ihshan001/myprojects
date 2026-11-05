//
//  QueueReadyView.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-09.
//

import SwiftUI

struct QueueReadyView: View {
    @State private var navigateToDoctorList = false
    var body: some View {
        ScrollView {
            ZStack {

                VStack(spacing: 32) {
                    QueueReady(
                        queueNumber: "11",
                        nowServing: "05",
                        estimatedWait: "~15 minutes"
                    )
                    
                  
                }
                
                
                
            }
        }
        .ignoresSafeArea()
    }
}

#Preview {
    QueueReadyView()
}
