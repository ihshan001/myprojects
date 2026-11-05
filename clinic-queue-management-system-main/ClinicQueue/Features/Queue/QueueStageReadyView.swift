//
//  QueueStageReadyView.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-10.
//

import SwiftUI

struct QueueStageReadyView: View{
    
    
    var body: some View {
        ScrollView {
            ZStack {

                VStack(spacing: 32) {
                    QueueWaite(
                        queueNumber: "11",
                      
                    )

                }
                
                
                
            }
        }
        .ignoresSafeArea()
    }
    
}

#Preview {
    QueueStageReadyView()
}

